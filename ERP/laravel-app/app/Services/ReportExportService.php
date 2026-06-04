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
        $warehouses = Warehouse::where('client_id', $clientId)->where('is_active', true)->get();
        $mainSub = $warehouses->whereIn('type', ['main', 'sub'])->values();
        $branches = $warehouses->where('type', 'branch')->values();
        $mainWh = $warehouses->where('type', 'main')->first();
        $closings = MonthlyClosing::where('client_id', $clientId)->where('month', $month)->get()->groupBy('item_id');

        $openingCount = count($mainSub);
        $receiptCount = count($mainSub);
        $consumptionCount = count($branches);
        $openingStart = 2; // after الصف and الوحدة
        $receiptStart = $openingStart + $openingCount;
        $consumptionStart = $receiptStart + $receiptCount;
        $theoreticalIdx = $consumptionStart + $consumptionCount;
        $actualCols = [];
        for ($i = 0; $i < $openingCount; $i++) {
            $actualCols[] = $theoreticalIdx + 1 + $i;
        }
        $diffIdx = end($actualCols) + 1;
        $priceIdx = $diffIdx + 1;
        $valueIdx = $priceIdx + 1;

        $headers = ['الصنف', 'الوحدة'];
        foreach ($mainSub as $wh) {
            $headers[] = "أول مدة {$wh->name}";
        }
        foreach ($mainSub as $wh) {
            $headers[] = "وارد {$wh->name}";
        }
        foreach ($branches as $br) {
            $headers[] = "منصرف {$br->name}";
        }
        $headers[] = 'اخر المده';
        foreach ($mainSub as $wh) {
            $headers[] = "رصيد فعلي {$wh->name}";
        }
        $headers[] = 'فرق';
        $headers[] = 'سعر';
        $headers[] = 'قيمة';

        $rows = [$headers];
        foreach ($items as $item) {
            $itemClosings = $closings->get($item->id, collect());
            $row = [$item->name, $item->unit];

            $totalOpening = 0;
            $totalReceipts = 0;
            $totalConsumption = 0;

            // Openings for warehouses only
            foreach ($mainSub as $wh) {
                $c = $itemClosings->where('warehouse_id', $wh->id)->first();
                $op = $c ? (float) $c->opening_qty : 0;
                $row[] = $op;
                $totalOpening += $op;
            }

            // Receipts for warehouses
            foreach ($mainSub as $wh) {
                $c = $itemClosings->where('warehouse_id', $wh->id)->first();
                $in = $c ? (float) $c->in_qty : 0;
                $row[] = $in;
                $totalReceipts += $in;
            }

            // Consumption per branch (net internal transfers to match grandSummary)
            foreach ($branches as $br) {
                $c = $itemClosings->where('warehouse_id', $br->id)->first();
                $con = $c ? ((float) $c->internal_in_qty - (float) $c->internal_out_qty) : 0;
                if ($con < 0) $con = 0;
                $row[] = $con;
                $totalConsumption += $con;
            }

            // Price
            $avgCost = 0;
            if ($mainWh) {
                $mc = $itemClosings->where('warehouse_id', $mainWh->id)->first();
                if ($mc) $avgCost = (float) $mc->avg_cost;
            }
            if ($avgCost <= 0) {
                foreach ($mainSub as $wh) {
                    $c = $itemClosings->where('warehouse_id', $wh->id)->first();
                    if ($c && (float) $c->avg_cost > 0) { $avgCost = (float) $c->avg_cost; break; }
                }
            }
            $price = $avgCost > 0 ? $avgCost : (float) ($item->default_cost ?? 0);

            // Pre-compute values for PDF (Excel will replace with formulas)
            $theoretical = round($totalOpening + $totalReceipts - $totalConsumption, 3);

            $row[] = $theoretical;

            // Actual per warehouse
            $totalActual = 0;
            foreach ($mainSub as $wh) {
                $c = $itemClosings->where('warehouse_id', $wh->id)->first();
                if ($c && $c->closing_qty_actual !== null) {
                    $actual = (float) $c->closing_qty_actual;
                    $row[] = $actual;
                    $totalActual += $actual;
                } else {
                    $row[] = '';
                }
            }

            // Diff = SUM(actuals) - theoretical
            $diff = $totalActual > 0 ? round($totalActual - $theoretical, 3) : '';
            $row[] = $diff;

            $row[] = $price > 0 ? round($price, 3) : 0;
            $row[] = round($price * $theoretical, 2);

            $rows[] = $row;
        }

        $meta = compact(
            'mainSub', 'branches', 'openingStart', 'openingCount', 'receiptCount',
            'consumptionStart', 'consumptionCount', 'theoreticalIdx', 'actualCols', 'diffIdx',
            'priceIdx', 'valueIdx'
        );
        return [$rows, $meta];
    }

    public function exportMatrixExcel($clientId, $month)
    {
        [$rows, $meta] = $this->matrixRows($clientId, $month);
        $colCount = count($rows[0] ?? []);

        ini_set('memory_limit', '512M');
        set_time_limit(300);
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet()->setRightToLeft(true);

        // Branding
        $sheet->mergeCells(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(1) . '1:' . \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(max($colCount, 1)) . '1');
        $sheet->setCellValue('A1', 'Curve Cost Control System - Ahmed Ali');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14)->getColor()->setARGB('FF1e3a5f');
        $sheet->getRowDimension(1)->setRowHeight(30);
        $dataStart = 2;

        // Column indices (1-based for Excel)
        $excelFormulaCols = [];
        $openingEnd = $meta['openingStart'] + $meta['openingCount'] - 1;
        $receiptEnd = $meta['openingStart'] + $meta['openingCount'] + $meta['receiptCount'] - 1;
        $consumptionEnd = $meta['consumptionStart'] + $meta['consumptionCount'] - 1;

        foreach ($rows as $ri => $row) {
            foreach ($row as $ci => $val) {
                $cellRef = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($ci + 1) . ($ri + $dataStart);
                if ($ri > 0) {
                    $excelRow = $ri + $dataStart;

                    // اخر المده = SUM(openings + receipts) - SUM(consumption)
                    if ($ci === $meta['theoreticalIdx']) {
                        $firstInflow = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($meta['openingStart'] + 1);
                        $lastInflow = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($receiptEnd + 1);
                        $firstOutflow = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($meta['consumptionStart'] + 1);
                        $lastOutflow = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($consumptionEnd + 1);
                        $formula = "=SUM({$firstInflow}{$excelRow}:{$lastInflow}{$excelRow})";
                        if ($meta['consumptionCount'] > 0) {
                            $formula .= "-SUM({$firstOutflow}{$excelRow}:{$lastOutflow}{$excelRow})";
                        }
                        $sheet->setCellValue($cellRef, $formula);
                        continue;
                    }

                    // فرق = IF(SUM(actuals)>0, SUM(actuals)-theoretical, "")
                    if ($ci === $meta['diffIdx']) {
                        $firstActualRef = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($meta['actualCols'][0] + 1) . $excelRow;
                        $lastActualRef = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(end($meta['actualCols']) + 1) . $excelRow;
                        $theoRef = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($meta['theoreticalIdx'] + 1) . $excelRow;
                        $sheet->setCellValue($cellRef, "=IF(SUM({$firstActualRef}:{$lastActualRef})>0,SUM({$firstActualRef}:{$lastActualRef})-{$theoRef},\"\")");
                        continue;
                    }

                    // قيمة = price * theoretical
                    if ($ci === $meta['valueIdx']) {
                        $priceRef = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($meta['priceIdx'] + 1) . $excelRow;
                        $theoRef = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($meta['theoreticalIdx'] + 1) . $excelRow;
                        $sheet->setCellValue($cellRef, "={$priceRef}*{$theoRef}");
                        continue;
                    }
                }
                $sheet->setCellValue($cellRef, $val);
            }
        }

        // Footer
        $footerRow = count($rows) + $dataStart;
        $sheet->mergeCells(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(1) . $footerRow . ':' . \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(max($colCount, 1)) . $footerRow);
        $sheet->setCellValue('A' . $footerRow, 'تم التصدير بواسطة Curve Cost Control System - Ahmed Ali');
        $sheet->getStyle('A' . $footerRow)->getFont()->setItalic(true)->setSize(9)->getColor()->setARGB('FF999999');

        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $writer->setPreCalculateFormulas(false);
        return response()->streamDownload(function () use ($writer) {
            $writer->save('php://output');
        }, "مصفوفة_خامات_{$month}.xlsx", ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    public function exportMatrixPdf($clientId, $month)
    {
        [$rows] = $this->matrixRows($clientId, $month);
        $html = $this->buildHtmlTable('مصفوفة الخامات', $month, $rows);
        return $this->streamPdf($html, "مصفوفة_خامات_{$month}.pdf");
    }

    // ── Single Location ──────────────────────────────────

    public function locationRows(string $clientId, string $warehouseId, string $month): array
    {
        $wh = Warehouse::find($warehouseId);
        $isBranch = $wh->type === 'branch';

        $closings = MonthlyClosing::where('client_id', $clientId)
            ->where('warehouse_id', $warehouseId)->where('month', $month)
            ->get()
            ->keyBy('item_id');

        $items = Item::where('client_id', $clientId)->where('is_active', true)
            ->orderBy('sort_order')->orderBy('name')
            ->get(['id', 'name', 'unit']);

        // Daily data
        $dt = \Carbon\Carbon::parse($month . '-01');
        $start = $dt->toDateString();
        $end = $dt->copy()->endOfMonth()->toDateString();
        $daysInMonth = $dt->daysInMonth;

        $dailyQuery = StockLedger::withoutGlobalScope('client')
            ->where('stock_ledger.client_id', $clientId)
            ->where('stock_ledger.warehouse_id', $warehouseId)
            ->whereBetween('stock_ledger.date', [$start, $end]);

        if ($isBranch) {
            $dailyQuery->whereIn('stock_ledger.movement_type', ['in', 'transfer_in'])
                ->join('dispatch_orders', function ($j) {
                    $j->on('stock_ledger.ref_id', '=', 'dispatch_orders.id')
                      ->where('stock_ledger.ref_type', '=', 'dispatch_order');
                })
                ->whereIn('dispatch_orders.type', ['purchase', 'dispatch']);
        } else {
            $dailyQuery->where('stock_ledger.movement_type', 'in')
                ->join('dispatch_orders', function ($j) {
                    $j->on('stock_ledger.ref_id', '=', 'dispatch_orders.id')
                      ->where('stock_ledger.ref_type', '=', 'dispatch_order');
                })
                ->where('dispatch_orders.type', 'purchase');
        }

        $dailyLines = $dailyQuery->get(['stock_ledger.item_id', 'stock_ledger.date', 'stock_ledger.qty']);

        $perItem = [];
        foreach ($dailyLines as $line) {
            $day = (int) \Carbon\Carbon::parse($line->date)->format('j');
            $perItem[$line->item_id][$day] = ($perItem[$line->item_id][$day] ?? 0) + (float) $line->qty;
        }

        // Build headers
        $headers = ['الصنف', 'الوحدة', 'أول المدة'];
        for ($d = 1; $d <= $daysInMonth; $d++) {
            $headers[] = (string) $d;
        }
        $headers[] = 'إجمالي الوارد';
        $headers[] = 'متوسط السعر';
        $headers[] = 'آخر المدة';
        if ($isBranch) { $headers[] = 'المستلم الفعلي'; }
        if (!$isBranch) {
            $headers[] = 'منصرف فروع';
            $headers[] = 'نظري';
        }
        $headers[] = 'قيمة أول المدة';
        $headers[] = 'قيمة المشتريات';
        $headers[] = 'قيمة آخر المدة';
        if ($isBranch) { $headers[] = 'قيمة المستلم الفعلي'; }
        if (!$isBranch) { $headers[] = 'قيمة النظري'; }
        if (!$isBranch) { $headers[] = 'الفرق'; $headers[] = 'قيمة الفرق'; }
        if (!$isBranch) { $headers[] = 'إجمالي المشتريات'; }

        $rows = [$headers];

        foreach ($items as $item) {
            $r = $closings->get($item->id);
            $avgCost = $r ? (float) $r->avg_cost : 0;
            $openingQty = $r ? (float) $r->opening_qty : 0;
            $openingVal = $r ? (float) $r->opening_value : 0;
            $inQty = $r ? (float) $r->in_qty : 0;
            $inVal = $r ? (float) $r->in_value : 0;
            $closingTheoretical = $r ? (float) $r->closing_qty_theoretical : 0;
            $closingActual = $r ? $r->closing_qty_actual : null;
            $diffQty = $r ? (float) $r->diff_qty : 0;
            $diffVal = $r ? (float) $r->diff_value : 0;

            $itemDays = $perItem[$item->id] ?? [];
            $dailyTotal = array_sum($itemDays);

            $row = [$item->name, $item->unit, $openingQty];

            for ($d = 1; $d <= $daysInMonth; $d++) {
                $row[] = $itemDays[$d] ?? 0;
            }

            $row[] = round($dailyTotal, 3);
            $row[] = round($avgCost, 3);
            $row[] = $closingActual ?? '';
            if ($isBranch) {
                $actualReceived = $openingQty + $inQty - ($closingActual ?? 0);
                $row[] = $actualReceived > 0 ? round($actualReceived, 3) : '';
            }
            if (!$isBranch) {
                $branchDispatchQty = $r ? collect((array) $r->branch_dispatches)->sum('qty') : 0;
                $row[] = $branchDispatchQty > 0 ? $branchDispatchQty : '';
                $row[] = $closingTheoretical;
            }
            $row[] = round($openingVal, 2);
            $row[] = round($inVal, 2);
            $row[] = $closingActual ? round($closingActual * $avgCost, 2) : '';
            if ($isBranch) { $row[] = $actualReceived > 0 ? round($actualReceived * $avgCost, 2) : ''; }
            if (!$isBranch) { $row[] = round($closingTheoretical * $avgCost, 2); }
            if (!$isBranch) { $row[] = $diffQty; $row[] = round($diffVal, 2); }
            if (!$isBranch) { $row[] = round($inVal > 0 ? $inVal : $inQty * $avgCost, 2); }

            $rows[] = $row;
        }

        return [$rows, $wh, $daysInMonth];
    }

    public function exportLocationExcel($clientId, $warehouseId, $month)
    {
        [$rows, $wh, $daysInMonth] = $this->locationRows($clientId, $warehouseId, $month);
        $isBranch = $wh->type === 'branch';

        ini_set('memory_limit', '512M');
        set_time_limit(300);
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet()->setRightToLeft(true);

        $colCount = count($rows[0]);

        // Column indices for formulas (0-based)
        $inIdx      = 3 + $daysInMonth;            // إجمالي الوارد
        $closingIdx = $inIdx + 2;                   // آخر المدة
        $openingIdx = 2;                            // أول المدة
        $valueOpeningIdx   = $isBranch ? $closingIdx + 2 : $closingIdx + 3; // قيمة أول المدة
        $valuePurchasesIdx = $isBranch ? $closingIdx + 3 : $closingIdx + 4; // قيمة المشتريات
        $valueClosingIdx   = $isBranch ? $closingIdx + 4 : $closingIdx + 5; // قيمة آخر المدة
        $valueOpeningCol   = Coordinate::stringFromColumnIndex($valueOpeningIdx + 1);
        $valuePurchasesCol = Coordinate::stringFromColumnIndex($valuePurchasesIdx + 1);
        $valueClosingCol   = Coordinate::stringFromColumnIndex($valueClosingIdx + 1);
        $openingValCol = Coordinate::stringFromColumnIndex($openingIdx + 1);
        $inColLetter   = Coordinate::stringFromColumnIndex($inIdx + 1);
        $closingColLetter = Coordinate::stringFromColumnIndex($closingIdx + 1);

        // Logo
        $logoPath = storage_path('app/public/logos/03EtHUemFuued8zOYvxSjmjflq3XR1cny1bjAYD6.png');
        if (file_exists($logoPath)) {
            $drawing = new \PhpOffice\PhpSpreadsheet\Worksheet\Drawing();
            $drawing->setName('Curve Logo');
            $drawing->setDescription('Logo');
            $drawing->setPath($logoPath);
            $drawing->setHeight(45);
            $drawing->setCoordinates('A1');
            $drawing->setOffsetX(5);
            $drawing->setOffsetY(5);
            $drawing->setWorksheet($sheet);
        }

        // Branding row
        $sheet->mergeCells('B1:' . Coordinate::stringFromColumnIndex($colCount) . '1');
        $sheet->setCellValue('B1', 'Curve Cost Control System - Ahmed Ali');
        $sheet->getStyle('B1')->getFont()->setBold(true)->setSize(16)->getColor()->setARGB('FF1e3a5f');
        $sheet->getStyle('B1')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle('B1')->getAlignment()->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);
        $sheet->getRowDimension(1)->setRowHeight(50);

        // Subtitle row
        $sheet->mergeCells('A2:' . Coordinate::stringFromColumnIndex($colCount) . '2');
        $sheet->setCellValue('A2', "الموقع: {$wh->name} | {$month}");
        $sheet->getStyle('A2')->getFont()->setSize(12)->getColor()->setARGB('FF555555');
        $sheet->getStyle('A2')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
        $sheet->getRowDimension(2)->setRowHeight(25);

        // Freeze first 3 columns and header
        $sheet->freezePane('D4');

        $dataStart = 3;

        // Write data
        $headerStyle = [
            'font' => ['bold' => true, 'size' => 10, 'color' => ['argb' => 'FFFFFFFF']],
            'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF1e3a5f']],
            'alignment' => ['horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER, 'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER],
            'borders' => ['allBorders' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_MEDIUM, 'color' => ['argb' => 'FF2d4a7a']]],
        ];

        foreach ($rows as $ri => $row) {
            foreach ($row as $ci => $val) {
                $cellRef = Coordinate::stringFromColumnIndex($ci + 1) . ($ri + $dataStart);
                $sheet->setCellValue($cellRef, $val);
                $style = $sheet->getStyle($cellRef);
                $style->getBorders()->getAllBorders()->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN);
                $style->getBorders()->getAllBorders()->getColor()->setARGB('FFCCCCCC');
                $style->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
                $style->getAlignment()->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);

                if ($ri === 0) {
                    // Header
                    $style->applyFromArray($headerStyle);
                } elseif ($ci === 0) {
                    // Item name - right align
                    $style->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_RIGHT);
                    $style->getFont()->setBold(true)->setSize(10);
                } elseif ($ci === 1) {
                    // Unit
                    $style->getFont()->getColor()->setARGB('FF888888');
                }

                // Color negative values red
                if (is_numeric($val) && $val < 0) {
                    $style->getFont()->getColor()->setARGB('FFDC2626');
                }
            }
        }

        // Alternating row colors
        $lastDataRow = count($rows) + $dataStart - 1;
        for ($ri = $dataStart + 1; $ri <= $lastDataRow; $ri++) {
            if (($ri - $dataStart) % 2 === 0) {
                for ($ci = 1; $ci <= $colCount; $ci++) {
                    $sheet->getStyle(Coordinate::stringFromColumnIndex($ci) . $ri)
                        ->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                        ->getStartColor()->setARGB('FFF1F5F9');
                }
            }
            $sheet->getRowDimension($ri)->setRowHeight(18);
        }

        // Column widths
        $sheet->getColumnDimension('A')->setWidth(28);
        $sheet->getColumnDimension('B')->setWidth(8);
        for ($ci = 3; $ci <= $colCount; $ci++) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($ci))->setWidth(9);
        }

        // Highlight key columns (purchases value, total purchases)
        $purchasesCol = 3 + $daysInMonth + 1 + 1 + 1 + ($isBranch ? 0 : 2) + 1 + 1; // قيمة المشتريات
        for ($ri = $dataStart + 1; $ri <= $lastDataRow; $ri++) {
            $ref = Coordinate::stringFromColumnIndex($purchasesCol) . $ri;
            $sheet->getStyle($ref)->getFont()->setBold(true)->getColor()->setARGB('FF16A34A');
        }

        // ── Summary rows ──────────────────────────────
        $sumRowStart = $lastDataRow + 2;

        $summaryLabelStyle = [
            'font' => ['bold' => true, 'size' => 10, 'color' => ['argb' => 'FF1e3a5f']],
            'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFEFF6FF']],
        ];
        $summaryValStyle = [
            'font' => ['bold' => true, 'size' => 10, 'color' => ['argb' => 'FF1e3a5f']],
            'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFEFF6FF']],
        ];

        $dataFirstRow = $dataStart; // row index in Excel for first data row (header row is $dataStart, data starts at $dataStart+1)
        $dataFirstDataRow = $dataStart + 1;

        // Compute received value in PHP
        $totReceivedVal = 0;
        foreach ($rows as $ri => $row) {
            if ($ri === 0) continue;
            $openingVal  = (float) ($row[$valueOpeningIdx] ?? 0);
            $purchasesVal = (float) ($row[$valuePurchasesIdx] ?? 0);
            $closingVal  = (float) ($row[$valueClosingIdx] ?? 0);
            $totReceivedVal += $openingVal + $purchasesVal - $closingVal;
        }

        $summaryData = [
            ['إجمالي قيمة أول المدة', "=SUM({$valueOpeningCol}{$dataFirstDataRow}:{$valueOpeningCol}{$lastDataRow})"],
            ['إجمالي قيمة المشتريات', "=SUM({$valuePurchasesCol}{$dataFirstDataRow}:{$valuePurchasesCol}{$lastDataRow})"],
            ['إجمالي قيمة آخر المدة', "=SUM({$valueClosingCol}{$dataFirstDataRow}:{$valueClosingCol}{$lastDataRow})"],
            ['إجمالي قيمة المستلم الفعلي', round($totReceivedVal, 2)],
        ];

        foreach ($summaryData as $si => $sRow) {
            $r = $sumRowStart + $si;
            $sheet->setCellValue('A' . $r, $sRow[0]);
            $sheet->getStyle('A' . $r)->applyFromArray($summaryLabelStyle);
            $sheet->getStyle('A' . $r)->getBorders()->getAllBorders()->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN);
            $sheet->getStyle('A' . $r)->getBorders()->getAllBorders()->getColor()->setARGB('FFBFDBFE');
            $sheet->mergeCells('B' . $r . ':' . Coordinate::stringFromColumnIndex($colCount - 1) . $r);
            $lastValCol = $colCount;
            $sheet->setCellValue(Coordinate::stringFromColumnIndex($lastValCol) . $r, $sRow[1]);
            $sheet->getStyle(Coordinate::stringFromColumnIndex($lastValCol) . $r)->applyFromArray($summaryValStyle);
            $sheet->getStyle(Coordinate::stringFromColumnIndex($lastValCol) . $r)->getBorders()->getAllBorders()->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN);
            $sheet->getStyle(Coordinate::stringFromColumnIndex($lastValCol) . $r)->getBorders()->getAllBorders()->getColor()->setARGB('FFBFDBFE');
            $sheet->getRowDimension($r)->setRowHeight(20);
        }

        $footerRow = $sumRowStart + count($summaryData);
        $sheet->mergeCells('A' . $footerRow . ':' . Coordinate::stringFromColumnIndex($colCount) . $footerRow);
        $sheet->setCellValue('A' . $footerRow, 'تم التصدير بواسطة Curve Cost Control System - Ahmed Ali');
        $sheet->getStyle('A' . $footerRow)->getFont()->setItalic(true)->setSize(9)->getColor()->setARGB('FF999999');
        $sheet->getStyle('A' . $footerRow)->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);

        $writer = new Xlsx($spreadsheet);
        $writer->setPreCalculateFormulas(false);
        return response()->streamDownload(function () use ($writer) {
            $writer->save('php://output');
        }, "تقفيل_{$month}_{$wh->name}.xlsx", ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    public function exportLocationPdf($clientId, $warehouseId, $month)
    {
        [$rows, $wh, $daysInMonth] = $this->locationRows($clientId, $warehouseId, $month);
        $isBranch = $wh->type === 'branch';

        $logoPath = storage_path('app/public/logos/03EtHUemFuued8zOYvxSjmjflq3XR1cny1bjAYD6.png');
        $logoHtml = '';
        if (file_exists($logoPath)) {
            $logoHtml = '<img src="' . $logoPath . '" style="max-height:50px;margin-bottom:4px;">';
        }

        $html = '<html><head><meta charset="utf-8"><style>
            @page { margin: 12px; }
            body { font-family: dejavusans; direction: rtl; font-size: 7px; }
            .header { text-align: center; margin-bottom: 10px; border-bottom: 2px solid #1e3a5f; padding-bottom: 8px; }
            .header h1 { font-size: 15px; color: #1e3a5f; margin: 2px 0; }
            .header .sub { font-size: 10px; color: #666; }
            table { width: 100%; border-collapse: collapse; margin-top: 4px; }
            th { background: #1e3a5f; color: white; font-weight: bold; padding: 4px 3px; border: 1px solid #2d4a7a; font-size: 6px; text-align: center; }
            td { padding: 3px 4px; border: 1px solid #d1d5db; font-size: 7px; text-align: center; }
            td:first-child { text-align: right; font-weight: bold; font-size: 8px; }
            tr:nth-child(even) td { background: #f1f5f9; }
            tr:nth-child(odd) td { background: #ffffff; }
            .footer { text-align: center; font-size: 8px; color: #999; margin-top: 8px; border-top: 1px solid #ddd; padding-top: 6px; }
        </style></head><body>';

        $html .= '<div class="header">';
        $html .= $logoHtml;
        $html .= '<h1>Curve Cost Control System - Ahmed Ali</h1>';
        $html .= '<div class="sub">' . $wh->name . ' — ' . $month . '</div>';
        $html .= '</div>';

        $html .= '<table><thead><tr>';
        foreach ($rows[0] as $header) {
            $html .= '<th>' . e((string) $header) . '</th>';
        }
        $html .= '</tr></thead><tbody>';
        for ($ri = 1; $ri < count($rows); $ri++) {
            $html .= '<tr>';
            foreach ($rows[$ri] as $ci => $cell) {
                $val = e((string) ($cell ?? ''));
                $style = '';
                if ($ci === 0) {
                    // Item name
                } elseif (is_numeric($cell) && $cell < 0) {
                    $style = ' style="color:#dc2626;font-weight:bold;"';
                }
                $html .= '<td' . $style . '>' . $val . '</td>';
            }
            $html .= '</tr>';
        }
        $html .= '</tbody></table>';

        // ── Summary section ─────────────────────────
        $totOpening = 0; $totPurchQty = 0; $totClosing = 0;
        $totDispatchQty = 0; $totReceivedQty = 0; $totReceivedVal = 0;

        for ($ri = 1; $ri < count($rows); $ri++) {
            $row = $rows[$ri];
            $totOpening += (float) ($row[2] ?? 0);
            $inIdx = 3 + $daysInMonth;
            $totPurchQty += (float) ($row[$inIdx] ?? 0);
            $avgC = (float) ($row[$inIdx + 1] ?? 0);
            $closingIdx = $inIdx + 2; // آخر المدة
            $closing = (float) ($row[$closingIdx] ?? 0);
            $totClosing += $closing;
            if (!$isBranch) {
                $totDispatchQty += (float) ($row[$closingIdx + 1] ?? 0);
            }
            $rQty = (float) ($row[2] ?? 0) + (float) ($row[$inIdx] ?? 0) - $closing;
            $totReceivedQty += $rQty;
            $totReceivedVal += $rQty * $avgC;
        }

        $html .= '<table style="margin-top:10px;width:50%;float:left;">';
        $html .= '<tr style="background:#eff6ff;"><th style="text-align:right;padding:4px 8px;border:1px solid #bfdbfe;color:#1e3a5f;">البيان</th><th style="text-align:center;padding:4px 8px;border:1px solid #bfdbfe;color:#1e3a5f;">القيمة</th></tr>';
        $summaryRows = [
            ['إجمالي أول المدة', $totOpening],
            ['إجمالي المشتريات', $totPurchQty],
            ['إجمالي آخر المدة', $totClosing],
        ];
        if (!$isBranch) {
            $summaryRows[] = ['إجمالي منصرف فروع', $totDispatchQty];
        }
        $summaryRows[] = ['المستلم الفعلي (كمية)', round($totReceivedQty, 3)];
        $summaryRows[] = ['المستلم الفعلي (قيمة)', number_format(round($totReceivedVal, 2), 2)];
        foreach ($summaryRows as $i => $sr) {
            $bg = $i % 2 === 0 ? '#f8fafc' : '#ffffff';
            $html .= '<tr style="background:' . $bg . ';">';
            $html .= '<td style="padding:3px 8px;border:1px solid #d1d5db;font-weight:bold;font-size:8px;">' . e($sr[0]) . '</td>';
            $html .= '<td style="padding:3px 8px;border:1px solid #d1d5db;text-align:center;font-weight:bold;font-size:8px;">' . e((string) $sr[1]) . '</td>';
            $html .= '</tr>';
        }
        $html .= '</table>';
        $html .= '<div style="clear:both;"></div>';
        $html .= '<div class="footer">تم التصدير بواسطة Curve Cost Control System - Ahmed Ali</div>';
        $html .= '</body></html>';

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
        $html .= '<div style="text-align:center;font-size:16px;font-weight:bold;color:#1e3a5f;margin-bottom:4px;">Curve Cost Control System - Ahmed Ali</div>';
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
        $html .= '<div class="note">تم التصدير بواسطة Curve Cost Control System - Ahmed Ali</div>';
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
        $sheet->setCellValue('A1', 'Curve Cost Control System - Ahmed Ali');
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
        $sheet->setCellValue('A' . $footerRow, 'تم التصدير بواسطة Curve Cost Control System - Ahmed Ali');
        $sheet->getStyle('A' . $footerRow)->getFont()->setItalic(true)->setSize(9)->getColor()->setARGB('FF999999');
        $writer = new Xlsx($spreadsheet);
        $writer->setPreCalculateFormulas(false);
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
