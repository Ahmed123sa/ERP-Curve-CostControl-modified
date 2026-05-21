<?php
namespace App\Services;

use App\Models\Item;
use App\Models\MonthlyClosing;
use App\Models\Warehouse;
use App\Models\StockLedger;
use Mpdf\Mpdf;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportExportService
{
    public function __construct(
        private CostCalculationService $calc,
    ) {}

    // ── Matrix ───────────────────────────────────────────

    public function matrixRows(string $clientId, string $month): array
    {
        $items = Item::where('client_id', $clientId)->where('is_active', true)->orderBy('sort_order')->orderBy('name')->get(['id', 'name', 'unit']);
        $locations = Warehouse::where('client_id', $clientId)->where('is_active', true)->get(['id', 'name', 'type'])
            ->groupBy(fn($w) => $w->name . '|' . $w->type)
            ->map(fn($g) => $g->sortByDesc(fn($w) => MonthlyClosing::where('month', $month)->where('warehouse_id', $w->id)->count())->first())
            ->values();
        $closings = MonthlyClosing::where('client_id', $clientId)->where('month', $month)->get()->groupBy('item_id');

        $headers = ['الصنف', 'الوحدة'];
        foreach ($locations as $loc) {
            $headers[] = $loc->name . ' - أول';
            $headers[] = $loc->name . ' - وارد';
        }
        $headers[] = 'إجمالي منصرف';
        $headers[] = 'نظري';
        $headers[] = 'فعلي';
        $headers[] = 'الفرق';

        $rows = [$headers];
        foreach ($items as $item) {
            $itemClosings = $closings->get($item->id, collect());
            $row = [$item->name, $item->unit];
            $totalOpening = 0; $totalIn = 0; $totalDispatch = 0; $totalActual = null;
            foreach ($locations as $loc) {
                $c = $itemClosings->where('warehouse_id', $loc->id)->first();
                $op = $c ? (float)$c->opening_qty : 0;
                $in = $c ? (float)$c->in_qty : 0;
                $row[] = $op;
                $row[] = $in;
                if ($loc->type === 'main' || $loc->type === 'sub') $totalOpening += $op;
                $totalIn += $in;
                if ($loc->type === 'main' || $loc->type === 'sub') $totalDispatch += $c ? (float)$c->internal_out_qty : 0;
                if ($c && $c->closing_qty_actual !== null) $totalActual = ($totalActual ?? 0) + (float)$c->closing_qty_actual;
            }
            $theoretical = $totalOpening + $totalIn - $totalDispatch;
            $diff = $totalActual !== null ? $theoretical - $totalActual : null;
            $row[] = $totalDispatch;
            $row[] = $theoretical;
            $row[] = $totalActual ?? '—';
            $row[] = $diff !== null ? $diff : '—';
            $rows[] = $row;
        }
        return [$rows, $locations];
    }

    public function exportMatrixExcel($clientId, $month)
    {
        [$rows, $locations] = $this->matrixRows($clientId, $month);
        return $this->streamExcel($rows, "مصفوفة_خامات_{$month}.xlsx");
    }

    public function exportMatrixPdf($clientId, $month)
    {
        [$rows, $locations] = $this->matrixRows($clientId, $month);
        $html = $this->buildHtmlTable('مصفوفة الخامات', $month, $rows);
        return $this->streamPdf($html, "مصفوفة_خامات_{$month}.pdf");
    }

    // ── Single Location ──────────────────────────────────

    public function locationRows(string $clientId, string $warehouseId, string $month): array
    {
        $wh = Warehouse::find($warehouseId);
        $closings = MonthlyClosing::where('client_id', $clientId)
            ->where('warehouse_id', $warehouseId)->where('month', $month)
            ->get()
            ->keyBy('item_id');

        $items = Item::where('client_id', $clientId)->where('is_active', true)
            ->orderBy('sort_order')->orderBy('name')
            ->get(['id', 'name', 'unit']);

        $rows = [['الصنف', 'الوحدة', 'أول المدة', 'قيمة أول', 'الوارد', 'قيمة وارد', 'المنصرف', 'متوسط السعر', 'نظري', 'فعلي', 'الفرق', 'قيمة الفرق']];
        foreach ($items as $item) {
            $r = $closings->get($item->id);
            $rows[] = [
                $item->name, $item->unit,
                $r ? $r->opening_qty : 0,
                $r ? $r->opening_value : 0,
                $r ? $r->in_qty : 0,
                $r ? $r->in_value : 0,
                $r ? $r->out_qty : 0,
                $r ? $r->avg_cost : 0,
                $r ? $r->closing_qty_theoretical : 0,
                $r ? ($r->closing_qty_actual ?? '') : '',
                $r ? $r->diff_qty : 0,
                $r ? $r->diff_value : 0,
            ];
        }
        return [$rows, $wh];
    }

    public function exportLocationExcel($clientId, $warehouseId, $month)
    {
        [$rows, $wh] = $this->locationRows($clientId, $warehouseId, $month);
        return $this->streamExcel($rows, "تقفيل_{$month}_{$wh->name}.xlsx");
    }

    public function exportLocationPdf($clientId, $warehouseId, $month)
    {
        [$rows, $wh] = $this->locationRows($clientId, $warehouseId, $month);
        $html = $this->buildHtmlTable("تقفيل {$wh->name}", $month, $rows);
        return $this->streamPdf($html, "تقفيل_{$month}_{$wh->name}.pdf");
    }

    // ── Financial Details ────────────────────────────────

    public function financialRows(string $clientId, string $month): array
    {
        $closings = MonthlyClosing::where('client_id', $clientId)->where('month', $month)
            ->with('warehouse:id,name,type')->get()->groupBy('warehouse_id');
        $warehouses = Warehouse::where('client_id', $clientId)->where('is_active', true)->get();
        $rows = [['الموقع', 'النوع', 'أول المدة', 'مشتريات', 'وارد داخلي', 'آخر المدة (نظري)', 'آخر المدة (فعلي)', 'المستلم الفعلي', 'الفروق', 'الأصناف']];
        $totals = ['opening' => 0, 'purchases' => 0, 'internal_in' => 0, 'closing' => 0, 'actual' => 0, 'received' => 0, 'diff' => 0];
        foreach ($warehouses as $wh) {
            $c = $closings->get($wh->id, collect());
            $isBranch = $wh->type === 'branch';
            $opening = (float)$c->sum('opening_value');
            $purchases = (float)$c->sum('purchases_value');
            $internalIn = (float)$c->sum('internal_in_value');
            $closing = (float)$c->sum('closing_value');
            $actual = $c->sum('closing_qty_actual') > 0 ? (float)$c->sum(fn($r) => ($r->closing_qty_actual ?? 0) * ($r->avg_cost ?? 0)) : null;
            $received = $opening + $purchases + $internalIn - ($actual ?? $closing);
            $diff = (float)$c->sum('diff_value');
            $itemCount = $c->count();
            $activeItems = $c->where('closing_qty_actual', '>', 0)->count();
            $rows[] = [
                $wh->name,
                $isBranch ? 'فرع' : ($wh->type === 'main' ? 'رئيسي' : 'فرعي'),
                $opening, $isBranch ? '—' : $purchases,
                $internalIn ?: '—', $isBranch ? '—' : $closing,
                $actual ? round($actual, 2) : '—', round($received, 2), $diff,
                "{$activeItems}/{$itemCount}",
            ];
            $totals['opening'] += $opening;
            $totals['purchases'] += $purchases;
            $totals['internal_in'] += $internalIn;
            $totals['closing'] += $closing;
            if ($actual) $totals['actual'] += $actual;
            $totals['received'] += $received;
            $totals['diff'] += $diff;
        }
        $rows[] = [];
        $rows[] = ['الإجمالي', '', $totals['opening'], $totals['purchases'], $totals['internal_in'], $totals['closing'], round($totals['actual'], 2) ?: '—', round($totals['received'], 2), $totals['diff'], ''];
        $rows[] = [];
        $rows[] = ['* المستلم الفعلي = أول المدة + المشتريات + وارد داخلي - آخر المدة (فعلي إن وجد وإلا صفر)'];
        return [$rows];
    }

    public function exportFinancialPdf($clientId, $month)
    {
        [$rows] = $this->financialRows($clientId, $month);
        $html = $this->buildHtmlTable('التفاصيل المالية', $month, $rows);
        return $this->streamPdf($html, "تفاصيل_مالية_{$month}.pdf");
    }

    // ── Dashboard ────────────────────────────────────────

    public function exportDashboard(string $clientId, string $month)
    {
        $kpis = $this->dashboardKpis($clientId, $month);
        $rows = [
            ['المؤشر', 'القيمة'],
            ['إجمالي المشتريات', $kpis['purchases']],
            ['إجمالي المنصرف', $kpis['dispatched']],
            ['إجمالي الفروق', $kpis['diffs']],
            ['نسبة تكلفة الطعام %', $kpis['foodCostPct'] . '%'],
        ];
        return $this->streamExcel($rows, "مؤشرات_الرئيسية_{$month}.xlsx");
    }

    public function dashboardKpis(string $clientId, string $month): array
    {
        $start = now()->parse($month . '-01')->toDateString();
        $end = now()->parse($month . '-01')->endOfMonth()->toDateString();
        $purchases = (float) StockLedger::where('client_id', $clientId)->whereBetween('date', [$start, $end])
            ->where('voucher_type', 'purchase')->where('movement_type', 'in')->sum('total_cost');
        $dispatched = (float) StockLedger::where('client_id', $clientId)->whereBetween('date', [$start, $end])
            ->whereIn('movement_type', ['out', 'transfer_out'])->sum('total_cost');
        $diffs = (float) MonthlyClosing::where('client_id', $clientId)->where('month', $month)->sum('diff_value');
        $foodCostPct = $purchases > 0 ? round(($dispatched / $purchases) * 100, 1) : 0;
        return compact('purchases', 'dispatched', 'diffs', 'foodCostPct');
    }

    // ── Shared ──────────────────────────────────────────

    private function buildHtmlTable(string $title, string $month, array $rows): string
    {
        $html = '<html><head><meta charset="utf-8"><style>
            body { font-family: dejavusans; direction: rtl; font-size: 8px; }
            h2 { text-align: center; margin: 8px 0; font-size: 14px; color: #1e3a5f; }
            .subtitle { text-align: center; font-size: 10px; color: #666; margin-bottom: 10px; }
            table { width: 100%; border-collapse: collapse; margin-top: 5px; }
            th { background: #1e3a5f; color: white; font-weight: bold; padding: 4px 6px; border: 1px solid #2d4a7a; font-size: 7px; }
            td { padding: 3px 5px; border: 1px solid #ccc; font-size: 7px; }
            tr:nth-child(even) { background: #f8fafc; }
            tr:nth-child(odd) { background: #ffffff; }
            tr:hover { background: #e8f0fe; }
            .totals-row td { background: #fff3cd !important; font-weight: bold; }
            .empty-row td { border: none; height: 5px; }
            .note { font-size: 8px; color: #6b7280; text-align: center; margin-top: 8px; }
        </style></head><body>';
        $html .= '<div style="text-align:center;font-size:16px;font-weight:bold;color:#1e3a5f;margin-bottom:4px;">Curve — نظام إدارة التكاليف</div>';
        $html .= '<h2>' . $title . '</h2>';
        $html .= '<div class="subtitle">' . $month . '</div>';
        $html .= '<table>';
        foreach ($rows as $ri => $row) {
            $isFirst = $ri === 0;
            $isLast = $ri === count($rows) - 1 && !empty($row) && count($row) > 0 && str_starts_with((string) $row[0] ?? '', '*');
            $isEmpty = empty($row) || (count($row) === 1 && empty($row[0]));
            $isTotal = !$isEmpty && !$isFirst && !$isLast && (($row[0] ?? '') === 'الإجمالي' || ($row[0] ?? '') === 'المجموع');
            if ($isEmpty) {
                $html .= '<tr class="empty-row"><td colspan="' . count($rows[0]) . '"></td></tr>';
                continue;
            }
            $class = $isTotal ? ' class="totals-row"' : '';
            $html .= '<tr' . $class . '>';
            foreach ($row as $ci => $cell) {
                $tag = $isFirst ? 'th' : 'td';
                $val = e((string) ($cell ?? ''));
                $html .= "<$tag>$val</$tag>";
            }
            $html .= '</tr>';
        }
        $html .= '</table>';
        $html .= '<div class="note">تم التصدير بواسطة Curve — نظام إدارة التكاليف</div>';
        $html .= '</body></html>';
        return $html;
    }

    private function streamExcel(array $rows, string $filename): StreamedResponse
    {
        ini_set('memory_limit', '512M');
        set_time_limit(120);
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet()->setRightToLeft(true);
        // Branding row
        $colCount = count($rows[0] ?? []);
        $sheet->mergeCells(Coordinate::stringFromColumnIndex(1) . '1:' . Coordinate::stringFromColumnIndex(max($colCount, 1)) . '1');
        $sheet->setCellValue('A1', 'Curve — نظام إدارة التكاليف');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14)->getColor()->setARGB('FF1e3a5f');
        $sheet->getRowDimension(1)->setRowHeight(30);
        $dataStart = 2;
        foreach ($rows as $ri => $row) {
            foreach ($row as $ci => $val) {
                $sheet->setCellValue(Coordinate::stringFromColumnIndex($ci + 1) . ($ri + $dataStart), $val);
            }
        }
        // Footer branding
        $footerRow = count($rows) + $dataStart;
        $sheet->mergeCells(Coordinate::stringFromColumnIndex(1) . $footerRow . ':' . Coordinate::stringFromColumnIndex(max($colCount, 1)) . $footerRow);
        $sheet->setCellValue('A' . $footerRow, 'تم التصدير بواسطة Curve — نظام إدارة التكاليف');
        $sheet->getStyle('A' . $footerRow)->getFont()->setItalic(true)->setSize(9)->getColor()->setARGB('FF999999');
        $writer = new Xlsx($spreadsheet);
        return response()->streamDownload(function () use ($writer) {
            $writer->save('php://output');
        }, $filename, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    private function streamPdf(string $html, string $filename): StreamedResponse
    {
        ini_set('memory_limit', '512M');
        set_time_limit(120);
        $mpdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4-L',
            'default_font' => 'dejavusans',
            'directionality' => 'rtl',
            'autoLangToFont' => true,
            'autoScriptToLang' => true,
        ]);
        $mpdf->WriteHTML($html);
        $content = $mpdf->Output('', 'S');
        return response()->streamDownload(function () use ($content) {
            echo $content;
        }, $filename, ['Content-Type' => 'application/pdf']);
    }
}
