<?php

namespace App\Services\Financial;

use App\Models\Financial\FinancialClosingReport;
use App\Models\Financial\FinancialClosingReportDetail;
use App\Models\Financial\FinancialDailyEntry;
use App\Models\Financial\FinancialDailyEntryDetail;
use App\Models\Financial\FinancialExpenseCategory;
use App\Models\Financial\FinancialClosingReportDetailItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
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
                ->selectRaw('financial_expense_categories.id, financial_expense_categories.name, financial_expense_categories.sort_order, financial_expense_categories.is_purchase, SUM(amount) as total')
                ->groupBy('financial_expense_categories.id', 'financial_expense_categories.name', 'financial_expense_categories.sort_order', 'financial_expense_categories.is_purchase')
                ->orderBy('financial_expense_categories.sort_order')
                ->get()
                ->keyBy('name');

            // Fetch all categories for the client (global + client-specific)
            $allCategories = FinancialExpenseCategory::where(function ($q) use ($clientId) {
                    $q->whereNull('client_id')->orWhere('client_id', $clientId);
                })
                ->orderBy('sort_order')
                ->get();

            $template = $this->buildTemplate($allCategories);

            // First pass: compute all non-formula values
            $values = [];
            foreach ($template as $item) {
                if ($item['row_type'] === 'section_header') {
                    $values[$item['key']] = 0;
                } elseif ($item['row_type'] === 'auto' || $item['row_type'] === 'manual') {
                    $values[$item['key']] = $this->resolveAutoValue($item, $categoryTotals, $totalSales);
                }
            }

            // Second pass: compute formula values
            foreach ($template as $item) {
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

            // Keep manual rows that were added by user
            $manualRows = $report->details()->where('row_type', 'manual')->get();
            $report->details()->where('row_type', '!=', 'manual')->delete();

            $sortOrder = 0;
            foreach ($template as $item) {
                $val = $values[$item['key']] ?? 0;
                $fc = $item['formula'] ?? [];

                $catId = $item['_category_id'] ?? null;

                $report->details()->create([
                    'client_id'      => $clientId,
                    'row_key'        => $item['key'],
                    'line_type'      => $item['line_type'],
                    'row_type'       => $item['row_type'],
                    'name'           => $item['name'],
                    'amount'         => $val,
                    'percentage'     => $totalSales > 0 ? round($val / $totalSales * 100, 2) : 0,
                    'formula_config' => !empty($fc) ? $fc : null,
                    'parent_id'      => null,
                    'category_id'    => $catId,
                    'sort_order'     => $sortOrder++,
                ]);
            }

            // Re-insert manual rows after template rows
            foreach ($manualRows as $mr) {
                $mr->update(['sort_order' => $sortOrder++]);
            }

            $report->load(['details' => fn($q) => $q->orderBy('sort_order'), 'details.items']);

            return $report;
        });
    }

    private function buildTemplate($allCategories): array
    {
        $purchaseCats = [];
        $expenseCats = [];

        foreach ($allCategories as $cat) {
            $item = [
                'key'           => 'cat_' . $cat->id,
                'name'          => $cat->name,
                'row_type'      => 'auto',
                'line_type'     => $cat->is_purchase ? 'purchase' : 'expense',
                'category_name' => $cat->name,
                '_category_id'  => $cat->id,
            ];
            if ($cat->is_purchase) {
                $purchaseCats[] = $item;
            } else {
                $expenseCats[] = $item;
            }
        }

        $purchaseKeys = array_map(fn($c) => $c['key'], $purchaseCats);
        $expenseKeys = array_map(fn($c) => $c['key'], $expenseCats);

        $template = [
            ['key' => 'revenue',           'name' => 'إجمالي مبيعات',      'row_type' => 'auto',    'line_type' => 'revenue',  'category_name' => null],
            ['key' => 'purchases_section', 'name' => 'المشتريات',          'row_type' => 'section_header', 'line_type' => 'purchase'],
        ];

        foreach ($purchaseCats as $pc) {
            $template[] = $pc;
        }

        $template[] = ['key' => 'total_purchases', 'name' => 'إجمالي المشتريات', 'row_type' => 'formula', 'line_type' => 'purchase',
            'formula' => ['type' => 'sum', 'keys' => $purchaseKeys]];

        $template[] = ['key' => 'expenses_section', 'name' => 'المصروفات', 'row_type' => 'section_header', 'line_type' => 'expense'];

        foreach ($expenseCats as $ec) {
            $template[] = $ec;
        }

        $template[] = ['key' => 'total_expenses', 'name' => 'إجمالي المصروفات', 'row_type' => 'formula', 'line_type' => 'expense',
            'formula' => ['type' => 'sum', 'keys' => $expenseKeys]];

        $template[] = ['key' => 'net_cash',   'name' => 'صافي نقدية',  'row_type' => 'formula', 'line_type' => 'profit',
            'formula' => ['type' => 'subtract', 'a' => 'revenue', 'b' => 'total_expenses']];
        $template[] = ['key' => 'net_profit', 'name' => 'صافي الربح',  'row_type' => 'formula', 'line_type' => 'profit',
            'formula' => ['type' => 'subtract', 'a' => 'revenue', 'b' => 'total_purchases']];

        return $template;
    }

    public function getFormulaText(array $formula, array $allDetails = []): string
    {
        $nameMap = collect($allDetails)->keyBy('row_key')->map(fn($d) => $d['name'])->toArray();

        if ($formula['type'] === 'sum') {
            $names = array_map(fn($k) => $nameMap[$k] ?? $k, $formula['keys'] ?? []);
            return '=SUM(' . implode(', ', $names) . ')';
        }
        if ($formula['type'] === 'subtract') {
            $a = $nameMap[$formula['a']] ?? $formula['a'];
            $b = $nameMap[$formula['b']] ?? $formula['b'];
            return '=' . $a . ' - ' . $b;
        }
        return '';
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

        $report = $detail->report;
        if ($report && $report->status !== 'draft') {
            abort(403, 'لا يمكن تعديل تقرير معتمد أو مغلق');
        }

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

    public function addCustomDetail(string $clientId, string $reportId, array $data): FinancialClosingReportDetail
    {
        $report = FinancialClosingReport::where('client_id', $clientId)->findOrFail($reportId);
        if ($report->status !== 'draft') {
            abort(403, 'لا يمكن تعديل تقرير معتمد أو مغلق');
        }

        $maxSort = $report->details()->max('sort_order') ?? 0;
        $raw = $report->details()->count();
        $key = 'manual_' . ($raw + 1);

        return $report->details()->create([
            'client_id'   => $clientId,
            'row_key'     => $key,
            'line_type'   => $data['line_type'] ?? 'expense',
            'row_type'    => 'manual',
            'name'        => $data['name'],
            'amount'      => $data['amount'] ?? 0,
            'percentage'  => 0,
            'sort_order'  => $maxSort + 1,
        ]);
    }

    public function deleteCustomDetail(string $clientId, string $detailId): void
    {
        $detail = FinancialClosingReportDetail::where('client_id', $clientId)
            ->where('row_type', 'manual')
            ->findOrFail($detailId);

        $report = $detail->report;
        if ($report && $report->status !== 'draft') {
            abort(403, 'لا يمكن تعديل تقرير معتمد أو مغلق');
        }

        $detail->delete();
    }

    public function updateDetailFormula(string $clientId, string $detailId, array $formula): FinancialClosingReportDetail
    {
        $detail = FinancialClosingReportDetail::where('client_id', $clientId)
            ->findOrFail($detailId);

        $report = $detail->report;
        if ($report && $report->status !== 'draft') {
            abort(403, 'لا يمكن تعديل تقرير معتمد أو مغلق');
        }

        if ($detail->row_type === 'formula' || $detail->row_type === 'auto') {
            $detail->update(['formula_config' => $formula]);
            if ($detail->row_type === 'auto') {
                $detail->update(['row_type' => 'formula']);
            }
        }

        return $detail->fresh();
    }

    // ====== Daily Entries for Detail Breakdown ======

    public function getDetailEntries(string $clientId, string $detailId): array
    {
        $detail = FinancialClosingReportDetail::where('client_id', $clientId)
            ->findOrFail($detailId);

        if (!$detail->category_id) {
            return [];
        }

        $report = $detail->report;
        $dateStart = sprintf('%04d-%02d-01', $report->year, $report->month);
        $dateEnd = date('Y-m-t', strtotime($dateStart));

        $entries = FinancialDailyEntry::where('client_id', $clientId)
            ->whereBetween('date', [$dateStart, $dateEnd])
            ->pluck('id');

        $details = FinancialDailyEntryDetail::withoutGlobalScope('client')
            ->where('financial_daily_entry_details.client_id', $clientId)
            ->whereIn('daily_entry_id', $entries)
            ->where('expense_category_id', $detail->category_id)
            ->join('financial_daily_entries', 'financial_daily_entries.id', '=', 'financial_daily_entry_details.daily_entry_id')
            ->select(
                'financial_daily_entry_details.id',
                'financial_daily_entry_details.description',
                'financial_daily_entry_details.amount',
                'financial_daily_entries.date',
                'financial_daily_entries.notes as entry_notes'
            )
            ->orderBy('financial_daily_entries.date')
            ->get()
            ->toArray();

        return $details;
    }

    // ====== Approval Workflow ======

    public function approve(string $clientId, string $reportId): FinancialClosingReport
    {
        $report = FinancialClosingReport::where('client_id', $clientId)->findOrFail($reportId);

        if ($report->status !== 'draft') {
            abort(400, 'يمكن اعتماد التقارير المسودة فقط');
        }

        $report->update([
            'status'       => 'approved',
            'approved_by'  => Auth::id(),
            'approved_at'  => now(),
        ]);

        return $report->fresh();
    }

    public function close(string $clientId, string $reportId): FinancialClosingReport
    {
        $report = FinancialClosingReport::where('client_id', $clientId)->findOrFail($reportId);

        if ($report->status !== 'approved') {
            abort(400, 'يجب اعتماد التقرير أولاً قبل الإغلاق');
        }

        $report->update([
            'status'     => 'closed',
            'closed_by'  => Auth::id(),
            'closed_at'  => now(),
        ]);

        return $report->fresh();
    }

    public function reopen(string $clientId, string $reportId): FinancialClosingReport
    {
        $report = FinancialClosingReport::where('client_id', $clientId)->findOrFail($reportId);

        if ($report->status === 'draft') {
            abort(400, 'التقرير بالفعل في حالة مسودة');
        }

        $report->update([
            'status'       => 'draft',
            'approved_by'  => null,
            'approved_at'  => null,
            'closed_by'    => null,
            'closed_at'    => null,
        ]);

        return $report->fresh();
    }

    // ====== Excel Export with Real Formulas ======

    public function exportExcel(string $clientId, string $reportId): StreamedResponse
    {
        $report = FinancialClosingReport::where('client_id', $clientId)
            ->with(['details' => fn($q) => $q->orderBy('sort_order')])
            ->findOrFail($reportId);

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('تقفيل');
        $sheet->setRightToLeft(true);

        $sheet->getColumnDimension('A')->setWidth(40);
        $sheet->getColumnDimension('B')->setWidth(18);
        $sheet->getColumnDimension('C')->setWidth(14);

        $headerFont = ['bold' => true, 'size' => 14, 'name' => 'Arial'];
        $colHeaderFont = ['bold' => true, 'size' => 11, 'name' => 'Arial'];
        $normalFont = ['size' => 11, 'name' => 'Arial'];
        $boldFont = ['bold' => true, 'size' => 11, 'name' => 'Arial'];
        $formulaFont = ['bold' => true, 'size' => 11, 'name' => 'Arial', 'italic' => true];
        $profitFont = ['bold' => true, 'size' => 12, 'name' => 'Arial'];

        $borderStyle = [
            'borders' => [
                'allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'CCCCCC']],
            ],
        ];

        $sheet->setCellValue('A1', 'تقرير التقفيل الشهري');
        $sheet->getStyle('A1')->getFont()->applyFromArray($headerFont);
        $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->mergeCells('A1:C1');
        $sheet->getRowDimension(1)->setRowHeight(30);

        $sheet->setCellValue('A2', $report->month . '/' . $report->year);
        $sheet->getStyle('A2')->getFont()->applyFromArray($colHeaderFont);
        $sheet->getStyle('A2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->mergeCells('A2:C2');

        $sheet->setCellValue('A3', 'البيان');
        $sheet->setCellValue('B3', 'القيمة');
        $sheet->setCellValue('C3', 'النسبة');

        $sheet->getStyle('A3:C3')->getFont()->applyFromArray($colHeaderFont);
        $sheet->getStyle('A3:C3')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF2F5496');
        $sheet->getStyle('A3:C3')->getFont()->getColor()->setARGB('FFFFFFFF');
        $sheet->getStyle('A3:C3')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle('A3:C3')->applyFromArray($borderStyle);

        $keyRowMap = [];
        $details = $report->details;
        $row = 4;

        foreach ($details as $detail) {
            $keyRowMap[$detail->row_key] = $row;
            $row++;
        }

        $currencyFormat = '#,##0.00';
        $row = 4;

        foreach ($details as $detail) {
            $isSection = $detail->row_type === 'section_header';
            $isFormula = $detail->row_type === 'formula';
            $isProfit = $detail->line_type === 'profit';

            $sheet->setCellValue('A' . $row, $detail->name);
            $sheet->getStyle('A' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

            if ($isSection) {
                $sheet->mergeCells('A' . $row . ':C' . $row);
                $sheet->getStyle('A' . $row . ':C' . $row)->getFill()
                    ->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFD6E4F0');
                $sheet->getStyle('A' . $row . ':C' . $row)->getFont()->applyFromArray($boldFont);
                $sheet->getStyle('A' . $row . ':C' . $row)->applyFromArray($borderStyle);
                $row++;
                continue;
            }

            if ($isFormula && $detail->formula_config) {
                $fc = $detail->formula_config;
                $formulaStr = $this->buildExcelFormula((array) $fc, $keyRowMap);
                if ($formulaStr) {
                    $sheet->setCellValue('B' . $row, $formulaStr);
                } else {
                    $sheet->setCellValue('B' . $row, (float) $detail->amount);
                }
                $formulaText = $this->getFormulaText((array) $fc, $details->toArray());
                $sheet->setCellValue('A' . $row, $detail->name . '  ' . $formulaText);
            } else {
                $sheet->setCellValue('B' . $row, (float) $detail->amount);
            }

            $salesRow = $keyRowMap['revenue'] ?? 4;
            $sheet->setCellValue('C' . $row, "=IF(B{$salesRow}=0,\"\",B{$row}/B{$salesRow})");
            $sheet->getStyle('C' . $row)->getNumberFormat()->setFormatCode('0.00%');
            $sheet->getStyle('B' . $row)->getNumberFormat()->setFormatCode($currencyFormat);

            $style = $sheet->getStyle('A' . $row . ':C' . $row);
            $style->applyFromArray($borderStyle);

            if ($isFormula && $isProfit) {
                $style->getFont()->applyFromArray($profitFont);
                $style->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFE2EFDA');
            } elseif ($isFormula) {
                $style->getFont()->applyFromArray($formulaFont);
                $style->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFF2F2F2');
            } elseif ($detail->row_key === 'revenue') {
                $style->getFont()->applyFromArray($boldFont);
                $style->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFE2EFDA');
            } else {
                $style->getFont()->applyFromArray($normalFont);
            }

            $row++;
        }

        $sheet->mergeCells('A' . $row . ':C' . $row);
        $sheet->setCellValue('A' . $row, 'نهاية التقرير');
        $sheet->getStyle('A' . $row)->getFont()->applyFromArray($boldFont);
        $sheet->getStyle('A' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle('A' . $row . ':C' . $row)
            ->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF2F5496');
        $sheet->getStyle('A' . $row . ':C' . $row)->getFont()->getColor()->setARGB('FFFFFFFF');

        $sheet->getPageSetup()->setOrientation(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_LANDSCAPE);
        $sheet->getPageSetup()->setPaperSize(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::PAPERSIZE_A4);
        $sheet->getPageMargins()->setTop(0.5);
        $sheet->getPageMargins()->setRight(0.5);
        $sheet->getPageMargins()->setBottom(0.5);
        $sheet->getPageMargins()->setLeft(0.5);

        $writer = new Xlsx($spreadsheet);
        $filename = sprintf('تقفيل_شهري_%s_%s.xlsx', $report->month, $report->year);

        $response = new StreamedResponse(function () use ($writer) {
            $writer->save('php://output');
        });

        $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');

        return $response;
    }

    private function buildExcelFormula(array $fc, array $keyRowMap): string
    {
        $type = $fc['type'] ?? 'sum';

        if ($type === 'sum') {
            $refs = [];
            foreach ($fc['keys'] ?? [] as $key) {
                if (isset($keyRowMap[$key])) {
                    $refs[] = 'B' . $keyRowMap[$key];
                }
            }
            if (empty($refs)) {
                return '';
            }
            return '=SUM(' . implode(',', $refs) . ')';
        }

        if ($type === 'subtract') {
            $a = $keyRowMap[$fc['a']] ?? null;
            $b = $keyRowMap[$fc['b']] ?? null;
            if (!$a || !$b) {
                return '';
            }
            return '=B' . $a . '-B' . $b;
        }

        return '';
    }
}
