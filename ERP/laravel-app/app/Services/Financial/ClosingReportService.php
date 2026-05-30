<?php

namespace App\Services\Financial;

use App\Models\Financial\FinancialClosingReport;
use App\Models\Financial\FinancialClosingReportDetail;
use App\Models\Financial\FinancialDailyEntry;
use App\Models\Financial\FinancialDailyEntryDetail;
use App\Models\Financial\FinancialExpenseCategory;
use App\Models\Financial\FinancialClosingReportDetailItem;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ClosingReportService
{
    public function list(string $clientId, ?int $month = null, ?int $year = null): array
    {
        $query = FinancialClosingReport::where('client_id', $clientId)
            ->with(['details' => fn($q) => $q->orderBy('sort_order'), 'details.items']);

        if ($month && $year) {
            $query->where('month', $month)->where('year', $year);
        }

        return $query->orderBy('year', 'desc')->orderBy('month', 'desc')->get()->toArray();
    }

    public function generate(string $clientId, int $month, int $year): FinancialClosingReport
    {
        return DB::transaction(function () use ($clientId, $month, $year) {
            $dateStart = sprintf('%04d-%02d-01', $year, $month);
            $dateEnd = date('Y-m-t', strtotime($dateStart));

            $entries = FinancialDailyEntry::where('client_id', $clientId)
                ->whereBetween('date', [$dateStart, $dateEnd])
                ->get();

            $totalSales = (float) $entries->sum('total_sales');

            // Get category totals from daily details
            $categoryTotals = FinancialDailyEntryDetail::withoutGlobalScope('client')
                ->where('financial_daily_entry_details.client_id', $clientId)
                ->whereIn('daily_entry_id', $entries->pluck('id'))
                ->join('financial_expense_categories', 'financial_daily_entry_details.expense_category_id', '=', 'financial_expense_categories.id')
                ->selectRaw('financial_expense_categories.name, financial_expense_categories.sort_order, SUM(amount) as total')
                ->groupBy('financial_expense_categories.name', 'financial_expense_categories.sort_order')
                ->orderBy('financial_expense_categories.sort_order')
                ->get()
                ->keyBy('name');

            // Build template items (matching the Excel report structure)
            $template = $this->buildTemplate();

            // First pass: compute all non-formula values
            $values = [];
            foreach ($template as $i => $item) {
                if ($item['row_type'] === 'section_header') {
                    $values[$item['key']] = 0;
                } elseif ($item['row_type'] === 'auto' || $item['row_type'] === 'manual') {
                    $values[$item['key']] = $this->resolveAutoValue($item, $categoryTotals, $totalSales);
                }
            }

            // Second pass: compute formula values (all references are now resolved)
            foreach ($template as $i => $item) {
                if ($item['row_type'] === 'formula') {
                    $values[$item['key']] = $this->resolveFormula($item, $values, $totalSales);
                }
            }

            // Create / update the report
            $report = FinancialClosingReport::updateOrCreate(
                ['client_id' => $clientId, 'month' => $month, 'year' => $year],
                [
                    'total_sales'     => $totalSales,
                    'total_purchases' => $values['total_purchases'] ?? 0,
                    'total_expenses'  => $values['total_expenses'] ?? 0,
                    'net_cash_profit' => $values['net_cash'] ?? 0,
                    'net_profit'      => $values['net_profit'] ?? 0,
                    'percentages_json' => [
                        'net_cash_percentage'  => $totalSales > 0 ? round(($values['net_cash'] ?? 0) / $totalSales * 100, 2) : 0,
                        'net_profit_percentage' => $totalSales > 0 ? round(($values['net_profit'] ?? 0) / $totalSales * 100, 2) : 0,
                    ],
                    'status' => 'draft',
                ]
            );

            // Replace details
            $report->details()->delete();

            $sortOrder = 0;
            foreach ($template as $item) {
                $val = $values[$item['key']] ?? 0;
                $report->details()->create([
                    'client_id'      => $clientId,
                    'line_type'      => $item['line_type'],
                    'row_type'       => $item['row_type'],
                    'name'           => $item['name'],
                    'amount'         => $val,
                    'percentage'     => $totalSales > 0 ? round($val / $totalSales * 100, 2) : 0,
                    'formula_config' => $item['formula'] ?? null,
                    'parent_id'      => null,
                    'category_id'    => $item['_category_id'] ?? null,
                    'sort_order'     => $sortOrder++,
                ]);
            }

            $report->load(['details' => fn($q) => $q->orderBy('sort_order'), 'details.items']);

            return $report;
        });
    }

    private function buildTemplate(): array
    {
        return [
            ['key' => 'revenue',           'name' => 'إجمالي مبيعات',           'row_type' => 'auto',    'line_type' => 'revenue',  'category_name' => null],
            ['key' => 'expenses_section',  'name' => 'مصروفات عامة',            'row_type' => 'section_header', 'line_type' => 'expense'],
            ['key' => 'general_expenses',  'name' => 'مصروفات عامة',           'row_type' => 'auto',    'line_type' => 'expense',  'category_name' => 'مصروفات عامة'],
            ['key' => 'maintenance',       'name' => 'صيانة',                  'row_type' => 'auto',    'line_type' => 'expense',  'category_name' => 'صيانة',       'has_detail' => true],
            ['key' => 'salaries',          'name' => 'رواتب',                  'row_type' => 'auto',    'line_type' => 'expense',  'category_name' => 'رواتب'],
            ['key' => 'general',           'name' => 'جينيرال',                'row_type' => 'auto',    'line_type' => 'expense',  'category_name' => 'جينيرال'],
            ['key' => 'electricity',       'name' => 'كهربا',                  'row_type' => 'auto',    'line_type' => 'expense',  'category_name' => 'كهربا'],
            ['key' => 'water',             'name' => 'مياه',                   'row_type' => 'auto',    'line_type' => 'expense',  'category_name' => 'مياه'],
            ['key' => 'hospitality',       'name' => 'ضيافة',                  'row_type' => 'auto',    'line_type' => 'expense',  'category_name' => 'ضيافة'],
            ['key' => 'other_bills',       'name' => 'فواتير أخرى',            'row_type' => 'auto',    'line_type' => 'expense',  'category_name' => 'فواتير أخرى', 'has_detail' => true],
            ['key' => 'visa',              'name' => 'فيزا',                   'row_type' => 'auto',    'line_type' => 'expense',  'category_name' => 'فيزا'],
            ['key' => 'assets',            'name' => 'أصول',                   'row_type' => 'auto',    'line_type' => 'expense',  'category_name' => 'أصول',        'has_detail' => true],
            ['key' => 'advances',          'name' => 'سلف',                    'row_type' => 'auto',    'line_type' => 'expense',  'category_name' => 'سلف'],
            ['key' => 'purchases_section', 'name' => 'مشتريات',                'row_type' => 'section_header', 'line_type' => 'purchase'],
            ['key' => 'bar_purchases',     'name' => 'مشتريات بار',            'row_type' => 'auto',    'line_type' => 'purchase', 'category_name' => 'مشتريات بار'],
            ['key' => 'kitchen_purchases', 'name' => 'مشتريات مطبخ',           'row_type' => 'auto',    'line_type' => 'purchase', 'category_name' => 'مشتريات مطبخ'],
            ['key' => 'shisha_purchases',  'name' => 'مشتريات شيشة',           'row_type' => 'auto',    'line_type' => 'purchase', 'category_name' => 'مشتريات شيشة'],
            ['key' => 'total_purchases',   'name' => 'إجمالي مشتريات',         'row_type' => 'formula', 'line_type' => 'purchase', 'formula' => ['type' => 'sum', 'keys' => ['bar_purchases', 'kitchen_purchases', 'shisha_purchases']]],
            ['key' => 'expenses_section2', 'name' => 'مصروفات',                'row_type' => 'section_header', 'line_type' => 'expense'],
            ['key' => 'rent',              'name' => 'إيجارات',                'row_type' => 'auto',    'line_type' => 'expense',  'category_name' => 'إيجارات'],
            ['key' => 'staff_housing',     'name' => 'إيجارات عاملين',         'row_type' => 'auto',    'line_type' => 'expense',  'category_name' => 'إيجارات عاملين'],
            ['key' => 'debt',              'name' => 'مديونية',                'row_type' => 'auto',    'line_type' => 'expense',  'category_name' => 'مديونية'],
            ['key' => 'safe_expenses',     'name' => 'مصاريف خزنة',            'row_type' => 'auto',    'line_type' => 'expense',  'category_name' => 'مصاريف خزنة'],
            ['key' => 'total_expenses',    'name' => 'إجمالي مصروفات',         'row_type' => 'formula', 'line_type' => 'expense',  'formula' => ['type' => 'sum', 'keys' => ['general_expenses', 'maintenance', 'salaries', 'general', 'electricity', 'water', 'hospitality', 'other_bills', 'visa', 'assets', 'advances', 'bar_purchases', 'kitchen_purchases', 'shisha_purchases', 'rent', 'staff_housing', 'debt', 'safe_expenses']]],
            ['key' => 'net_cash',          'name' => 'صافي نقدية (ربح نقدي)',  'row_type' => 'formula', 'line_type' => 'profit',   'formula' => ['type' => 'subtract', 'a' => 'revenue', 'b' => 'total_expenses']],
            ['key' => 'net_profit',        'name' => 'ربح صافي',               'row_type' => 'formula', 'line_type' => 'profit',   'formula' => ['type' => 'subtract', 'a' => 'revenue', 'b' => 'total_purchases']],
        ];
    }

    private function resolveAutoValue(array $item, $categoryTotals, float $totalSales): float
    {
        $catName = $item['category_name'] ?? null;
        if ($catName === null) {
            return $totalSales;
        }
        $cat = $categoryTotals->get($catName);
        return $cat ? (float) $cat->total : 0;
    }

    private function resolveFormula(array $item, array &$values, float $totalSales): float
    {
        $formula = $item['formula'] ?? [];
        $type = $formula['type'] ?? 'sum';

        if ($type === 'sum') {
            $total = 0;
            foreach ($formula['keys'] ?? [] as $key) {
                $total += $values[$key] ?? 0;
            }
            return round($total, 3);
        }

        if ($type === 'subtract') {
            $a = $values[$formula['a']] ?? 0;
            $b = $values[$formula['b']] ?? 0;
            return round($a - $b, 3);
        }

        return 0;
    }

    public function updateDetail(string $clientId, string $detailId, array $data): FinancialClosingReportDetail
    {
        $detail = FinancialClosingReportDetail::where('client_id', $clientId)
            ->findOrFail($detailId);

        if ($detail->row_type === 'manual' || $detail->row_type === 'auto') {
            $detail->update([
                'amount' => $data['amount'] ?? $detail->amount,
                'name'   => $data['name'] ?? $detail->name,
            ]);
        }

        return $detail->fresh();
    }

    public function addDetailItem(string $clientId, string $detailId, array $data)
    {
        $detail = FinancialClosingReportDetail::where('client_id', $clientId)
            ->findOrFail($detailId);

        return $detail->items()->create([
            'client_id'         => $clientId,
            'closing_report_id' => $detail->closing_report_id,
            'name'              => $data['name'],
            'amount'            => $data['amount'] ?? 0,
            'sort_order'        => $data['sort_order'] ?? 0,
        ]);
    }

    public function deleteDetailItem(string $clientId, string $itemId): void
    {
        $item = FinancialClosingReportDetailItem::where('client_id', $clientId)
            ->findOrFail($itemId);
        $item->delete();
    }

    public function exportExcel(string $clientId, string $reportId): StreamedResponse
    {
        $report = FinancialClosingReport::where('client_id', $clientId)
            ->with(['details' => fn($q) => $q->orderBy('sort_order'), 'details.items'])
            ->findOrFail($reportId);

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('تقرير');
        $sheet->setRightToLeft(true);

        // Title row
        $sheet->setCellValue('A1', 'تقفيل شركة');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->mergeCells('A1:E1');

        // Headers
        $sheet->setCellValue('A2', 'البيان');
        $sheet->setCellValue('B2', 'القيمة');
        $sheet->setCellValue('C2', 'النسبة');
        $sheet->getStyle('A2:C2')->getFont()->setBold(true);
        $sheet->getStyle('A2:C2')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFF0F0F0');

        $row = 3;
        foreach ($report->details as $detail) {
            if ($detail->row_type === 'section_header') {
                // Section header with border
                $sheet->setCellValue('A' . $row, $detail->name);
                $sheet->getStyle('A' . $row)->getFont()->setBold(true);
                $sheet->getStyle('A' . $row . ':C' . $row)->getFill()
                    ->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFF5F5F5');
                $sheet->getStyle('A' . $row . ':C' . $row)->getBorders()->getTop()
                    ->setBorderStyle(Border::BORDER_THIN);
                $row++;
                continue;
            }

            $isFormula = $detail->row_type === 'formula';
            $isProfit = $detail->line_type === 'profit';
            $isRevenue = $detail->line_type === 'revenue';

            $sheet->setCellValue('A' . $row, ($isFormula ? '═ ' : '') . $detail->name);
            $sheet->setCellValue('B' . $row, (float) $detail->amount);
            $sheet->setCellValue('C' . $row, $detail->percentage > 0 ? $detail->percentage . '%' : '');

            $style = $sheet->getStyle('A' . $row . ':C' . $row);
            if ($isRevenue) {
                $style->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFF0FFF0');
                $style->getFont()->setBold(true);
            } elseif ($isProfit) {
                $style->getFont()->setBold(true)->setSize(12);
            } elseif ($isFormula) {
                $style->getFont()->setBold(true);
                $style->getBorders()->getTop()->setBorderStyle(Border::BORDER_THIN);
            }

            $sheet->getStyle('B' . $row)->getNumberFormat()->setFormatCode('#,##0.000');

            // Detail items (breakdown) indented below each row
            if ($detail->items && $detail->items->count() > 0) {
                $row++;
                foreach ($detail->items as $item) {
                    $sheet->setCellValue('A' . $row, '    ' . $item->name);
                    $sheet->setCellValue('B' . $row, (float) $item->amount);
                    $sheet->getStyle('A' . $row)->getFont()->setItalic(true)->setSize(10);
                    $sheet->getStyle('A' . $row . ':B' . $row)->getFill()
                        ->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFFAFAFA');
                    $row++;
                }
                $row--; // undo last increment since main loop will increment
            }

            $row++;
        }

        // Auto-size columns
        foreach (range('A', 'C') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $writer = new Xlsx($spreadsheet);
        $filename = sprintf('تقفيل_شهري_%s_%s.xlsx', $report->month, $report->year);

        $response = new StreamedResponse(function () use ($writer) {
            $writer->save('php://output');
        });

        $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');

        return $response;
    }
}
