<?php

namespace App\Services\Financial;

use App\Models\Financial\FinancialClosingReport;
use App\Models\Financial\FinancialClosingReportDetail;
use App\Models\Financial\FinancialDailyEntry;
use App\Models\Financial\FinancialDailyEntryDetail;
use App\Models\Financial\FinancialExpenseCategory;
use App\Models\Financial\FinancialClosingReportDetailItem;
use App\Models\Financial\EmployeeAdvance;
use App\Models\Financial\FinancialEmployee;
use App\Models\Payroll\PayrollMonthly;
use App\Models\Payroll\PayrollMonthlyDetail;
use App\Models\Client;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use Barryvdh\DomPDF\Facade\Pdf;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ClosingReportService
{
    public function list(string $clientId, ?int $month = null, ?int $year = null): array
    {
        $cacheKey = "fin_report_list:{$clientId}:" . ($month ?? 'any') . ":" . ($year ?? 'any');
        return Cache::remember($cacheKey, 300, function () use ($clientId, $month, $year) {
            $query = FinancialClosingReport::where('client_id', $clientId)
                ->with(['details' => fn($q) => $q->orderBy('sort_order'), 'details.items']);

            if ($month && $year) {
                $query->where('month', $month)->where('year', $year);
            }

            return $query->orderBy('year', 'desc')->orderBy('month', 'desc')->get()->toArray();
        });
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

            // Collect existing manual rows (user-added OR auto→manual overrides)
            $manualRows = $report->details()->where('row_type', 'manual')->get()->keyBy('row_key');
            $manualRowKeys = $manualRows->keys()->toArray();

            // Collect existing custom formula configs so user changes survive regeneration
            $storedFormulas = $report->details()
                ->where('row_type', 'formula')
                ->whereNotNull('formula_config')
                ->get()
                ->keyBy('row_key')
                ->map(fn($d) => (array) $d->formula_config)
                ->toArray();

            // Merge stored formula configs into template
            foreach ($template as &$item) {
                if (isset($storedFormulas[$item['key']])) {
                    $item['formula'] = $storedFormulas[$item['key']];
                }
            }
            unset($item);

            // Override computed values with manual amounts
            foreach ($manualRows as $key => $mr) {
                $values[$key] = (float) $mr->amount;
            }

            // Recalculate formulas with manual overrides applied
            foreach ($template as $item) {
                if ($item['row_type'] === 'formula') {
                    $values[$item['key']] = $this->resolveFormula($item, $values, $totalSales);
                }
            }

            // Update report totals with recalculated values (after manual overrides)
            $report->update([
                'total_sales'     => $totalSales,
                'total_purchases' => $values['total_purchases'] ?? 0,
                'total_expenses'  => $values['total_expenses'] ?? 0,
                'net_cash_profit' => $values['net_cash'] ?? 0,
                'net_profit'      => $values['net_profit'] ?? 0,
                'percentages_json' => [
                    'net_cash_percentage'  => $totalSales > 0 ? round(($values['net_cash'] ?? 0) / $totalSales * 100, 2) : 0,
                    'net_profit_percentage' => $totalSales > 0 ? round(($values['net_profit'] ?? 0) / $totalSales * 100, 2) : 0,
                ],
            ]);

            // Preserve manual sort_orders, delete rest
            $occupiedSortOrders = $manualRows->pluck('sort_order')->toArray();
            $report->details()->where('row_type', '!=', 'manual')->delete();

            $sortOrder = 0;

            foreach ($template as $item) {
                if (in_array($item['key'], $manualRowKeys)) {
                    continue;
                }

                $val = $values[$item['key']] ?? 0;
                $fc = $item['formula'] ?? [];
                $catId = $item['_category_id'] ?? null;

                // Skip sort_orders occupied by manual rows
                while (in_array($sortOrder, $occupiedSortOrders)) {
                    $sortOrder++;
                }

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

            // Manual rows keep their original sort_order (already interleaved)

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

        if ($formula['type'] === 'custom') {
            $expr = $formula['expression'] ?? '';
            // Replace row keys (including UUID-based) with display names
            $keys = array_keys($nameMap);
            usort($keys, fn($a, $b) => strlen($b) - strlen($a));
            foreach ($keys as $key) {
                $expr = str_replace($key, $nameMap[$key], $expr);
            }
            return '= ' . $expr;
        }

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

        if ($type === 'custom') {
            $expression = $formula['expression'] ?? '';
            return round($this->evaluateExpression($expression, $values), 3);
        }

        return 0;
    }

    private function evaluateExpression(string $expression, array $values): float
    {
        // Replace known row keys (including UUIDs with hyphens) with their numeric values
        // Sort by key length descending to avoid partial matches
        $keys = array_keys($values);
        usort($keys, fn($a, $b) => strlen($b) - strlen($a));

        $expr = $expression;
        foreach ($keys as $key) {
            $val = sprintf('%.4F', $values[$key]);
            $expr = str_replace($key, $val, $expr);
        }

        // Remove whitespace
        $expr = preg_replace('/\s+/', '', $expr);

        // Strict validation: only digits, +, -, *, /, ., (, )
        if (!preg_match('/^[\d+\-*\/.()]+$/', $expr)) {
            return 0;
        }

        // Use eval with extreme caution — input is strictly validated
        $result = @eval("return $expr;");
        if ($result === false || !is_numeric($result)) {
            return 0;
        }
        return (float) $result;
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
            $updates = [
                'amount' => $data['amount'] ?? $detail->amount,
                'name'   => $data['name'] ?? $detail->name,
            ];
            // Lock auto rows to manual when user overrides the amount
            if ($detail->row_type === 'auto' && array_key_exists('amount', $data)) {
                $updates['row_type'] = 'manual';
            }
            $detail->update($updates);

            // Auto-recalculate formulas after manual edit
            if ($report) {
                $this->recalculateFormulas($report);
            }
        }

        return $detail->fresh();
    }

    public function applyLinkValue(string $clientId, string $detailId, float $value): FinancialClosingReportDetail
    {
        $detail = FinancialClosingReportDetail::where('client_id', $clientId)
            ->findOrFail($detailId);

        $report = $detail->report;
        if ($report && $report->status !== 'draft') {
            abort(403, 'لا يمكن تعديل تقرير معتمد أو مغلق');
        }

        $detail->update([
            'amount'   => $value,
            'row_type' => 'manual',
        ]);

        // Recalculate formulas with new linked value
        if ($report) {
            $this->recalculateFormulas($report);
        }

        return $detail->fresh();
    }

    private function recalculateFormulas(FinancialClosingReport $report): void
    {
        $details = $report->details()->orderBy('sort_order')->get();

        $values = [];
        $totalSales = (float) $report->total_sales;

        foreach ($details as $detail) {
            if ($detail->row_type === 'section_header') {
                $values[$detail->row_key] = 0;
            } elseif ($detail->row_type === 'auto' || $detail->row_type === 'manual') {
                $values[$detail->row_key] = (float) $detail->amount;
            }
        }

        foreach ($details as $detail) {
            if ($detail->row_type === 'formula' && $detail->formula_config) {
                $item = ['formula' => (array) $detail->formula_config];
                $newAmount = $this->resolveFormula($item, $values, $totalSales);
                $detail->update(['amount' => $newAmount]);
                $values[$detail->row_key] = $newAmount;
            }
        }

        $report->update([
            'total_sales'     => $totalSales,
            'total_purchases' => $values['total_purchases'] ?? 0,
            'total_expenses'  => $values['total_expenses'] ?? 0,
            'net_cash_profit' => $values['net_cash'] ?? 0,
            'net_profit'      => $values['net_profit'] ?? 0,
            'percentages_json' => [
                'net_cash_percentage'  => $totalSales > 0 ? round(($values['net_cash'] ?? 0) / $totalSales * 100, 2) : 0,
                'net_profit_percentage' => $totalSales > 0 ? round(($values['net_profit'] ?? 0) / $totalSales * 100, 2) : 0,
            ],
        ]);
    }

    public function resetDetailToAuto(string $clientId, string $detailId): FinancialClosingReportDetail
    {
        $detail = FinancialClosingReportDetail::where('client_id', $clientId)
            ->findOrFail($detailId);

        $report = $detail->report;
        if ($report && $report->status !== 'draft') {
            abort(403, 'لا يمكن تعديل تقرير معتمد أو مغلق');
        }

        if ($detail->row_type !== 'manual' || !$detail->category_id) {
            abort(422, 'لا يمكن إعادة التعيين التلقائي لهذا البند');
        }

        // Revert to auto — next generate will recompute it from daily entries
        $detail->update(['row_type' => 'auto']);

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
            // Handle custom text expression: convert 'revenue - purchases' to structured config
            if (isset($formula['expression'])) {
                $formula = [
                    'type' => 'custom',
                    'expression' => $formula['expression'],
                ];
            }
            $detail->update(['formula_config' => $formula]);
            if ($detail->row_type === 'auto') {
                $detail->update(['row_type' => 'formula']);
            }

            // Recalculate all formulas with the new config
            if ($report) {
                $this->recalculateFormulas($report);
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

    // ====== Link Advances & Salaries ======

    public function getLinkAdvances(string $clientId, string $reportId): array
    {
        $report = FinancialClosingReport::where('client_id', $clientId)->findOrFail($reportId);
        $monthStr = sprintf('%04d-%02d', $report->year, $report->month);

        $employees = FinancialEmployee::where('client_id', $clientId)
            ->orderBy('name')
            ->get();

        $advances = EmployeeAdvance::where('client_id', $clientId)
            ->whereYear('date', $report->year)
            ->whereMonth('date', $report->month)
            ->get();

        $perEmployee = [];
        foreach ($employees as $emp) {
            $empAdvances = $advances->where('employee_id', $emp->id);
            if ($empAdvances->isEmpty()) continue;

            $perEmployee[] = [
                'name'    => $emp->name,
                'total'   => (float) $empAdvances->sum('amount'),
                'entries' => $empAdvances->map(fn($a) => [
                    'date'   => $a->date->format('Y-m-d'),
                    'amount' => (float) $a->amount,
                    'notes'  => $a->notes,
                ])->values(),
            ];
        }

        return [
            'month'        => $monthStr,
            'total_all'    => (float) $advances->sum('amount'),
            'employees'    => $perEmployee,
        ];
    }

    public function getLinkSalaries(string $clientId, string $reportId): array
    {
        $report = FinancialClosingReport::where('client_id', $clientId)->findOrFail($reportId);

        $payroll = PayrollMonthly::where('client_id', $clientId)
            ->where('month', $report->month)
            ->where('year', $report->year)
            ->where('status', 'approved')
            ->with('details.employee', 'details.bonusItems')
            ->first();

        if (!$payroll) {
            return [
                'month'      => sprintf('%04d-%02d', $report->year, $report->month),
                'total_all'  => 0,
                'status'     => null,
                'employees'  => [],
            ];
        }

        $perEmployee = $payroll->details->map(fn($d) => [
            'name'           => $d->employee?->name ?? '—',
            'base_salary'    => (float) $d->base_salary_snapshot,
            'work_days'      => $d->work_days,
            'overtime_amount'=> (float) $d->overtime_amount,
            'advance_amount' => (float) $d->advance_amount,
            'bonus_total'    => (float) $d->bonus_total,
            'total_deductions' => (float) $d->total_deductions,
            'net_salary'     => (float) $d->net_salary,
        ])->toArray();

        return [
            'month'      => sprintf('%04d-%02d', $report->year, $report->month),
            'total_all'  => (float) $payroll->details->sum('net_salary'),
            'status'     => $payroll->status,
            'employees'  => $perEmployee,
        ];
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

    public function exportExcel(string $clientId, string $reportId, array $detailIds = []): StreamedResponse
    {
        $report = FinancialClosingReport::where('client_id', $clientId)
            ->with(['details' => fn($q) => $q->orderBy('sort_order'), 'details.items' => fn($q) => $q->orderBy('sort_order')])
            ->findOrFail($reportId);

        $spreadsheet = new Spreadsheet();
        $currencyFormat = '#,##0.00';

        // -- Client / Month helpers --
        $client = Client::find($clientId);
        $clientName = $client ? $client->name : '';
        $monthName = ['', 'يناير', 'فبراير', 'مارس', 'أبريل', 'مايو', 'يونيو', 'يوليو', 'أغسطس', 'سبتمبر', 'أكتوبر', 'نوفمبر', 'ديسمبر'][$report->month] ?? $report->month;
        $sysName = config('app.name', 'ERP CostControl');

        // -- Fetch daily entry details for category-linked rows --
        $catIds = $report->details->pluck('category_id')->filter()->unique()->toArray();
        $entryDetailsByCat = collect();
        if (!empty($catIds)) {
            $dateStart = sprintf('%04d-%02d-01', $report->year, $report->month);
            $dateEnd = date('Y-m-t', strtotime($dateStart));

            $entryDetailsByCat = FinancialDailyEntryDetail::withoutGlobalScope('client')
                ->where('financial_daily_entry_details.client_id', $clientId)
                ->whereIn('financial_daily_entry_details.expense_category_id', $catIds)
                ->join('financial_daily_entries', 'financial_daily_entry_details.daily_entry_id', '=', 'financial_daily_entries.id')
                ->whereBetween('financial_daily_entries.date', [$dateStart, $dateEnd])
                ->join('financial_expense_categories', 'financial_daily_entry_details.expense_category_id', '=', 'financial_expense_categories.id')
                ->selectRaw('financial_daily_entry_details.*, financial_daily_entries.date as entry_date, financial_daily_entries.notes as entry_notes, financial_expense_categories.name as cat_name')
                ->orderBy('financial_daily_entries.date')
                ->get()
                ->groupBy('cat_name');
        }

        // ========== Sheet 1: P&L Report ==========
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('التقفيل');
        $sheet->setRightToLeft(true);

        $sheet->getColumnDimension('A')->setWidth(48);
        $sheet->getColumnDimension('B')->setWidth(20);
        $sheet->getColumnDimension('C')->setWidth(16);

        // Colors
        $darkBlue = 'FF2F5496';
        $lightBlue = 'FFD6E4F0';
        $lightGreen = 'FFE2EFDA';
        $lightGray = 'FFF2F2F2';
        $white = 'FFFFFFFF';
        $borderColor = 'FFD0D0D0';

        $borderStyle = [
            'borders' => [
                'allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => $borderColor]],
            ],
        ];

        // -- Row 1: Logo + System Name + Client + Title --
        $sheet->mergeCells('A1:C1');
        $sheet->setCellValue('A1', $sysName . ' | ' . $clientName);
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14)->setName('Arial')->getColor()->setARGB($darkBlue);
        $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
        $sheet->getRowDimension(1)->setRowHeight(40);

        // Logo (overlaid on A1, after mergeCells)
        if ($client && $client->logo && Storage::disk('public')->exists($client->logo)) {
            $drawing = new Drawing();
            $drawing->setPath(Storage::disk('public')->path($client->logo));
            $drawing->setHeight(40);
            $drawing->setCoordinates('A1');
            $drawing->setOffsetX(5);
            $drawing->setOffsetY(2);
            $drawing->setWorksheet($sheet);
        }

        // -- Row 2: Title --
        $sheet->mergeCells('A2:C2');
        $sheet->setCellValue('A2', 'تقرير التقفيل المالي الشهري — ' . $monthName . ' ' . $report->year);
        $sheet->getStyle('A2')->getFont()->setBold(true)->setSize(13)->setName('Arial')->getColor()->setARGB('FF1e3a5f');
        $sheet->getStyle('A2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getRowDimension(2)->setRowHeight(26);

        // -- Row 3: Column Headers --
        $sheet->setCellValue('A3', 'البيان');
        $sheet->setCellValue('B3', 'القيمة');
        $sheet->setCellValue('C3', 'النسبة');
        $sheet->getStyle('A3:C3')->getFont()->setBold(true)->setSize(11)->setName('Arial')->getColor()->setARGB($white);
        $sheet->getStyle('A3:C3')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB($darkBlue);
        $sheet->getStyle('A3:C3')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle('A3:C3')->applyFromArray($borderStyle);
        $sheet->getRowDimension(3)->setRowHeight(24);

        // -- Build key→row map --
        $keyRowMap = [];
        $details = $report->details;
        $row = 4;
        foreach ($details as $detail) {
            $keyRowMap[$detail->row_key] = $row;
            $row++;
        }

        // -- Data rows --
        $row = 4;
        $alternate = false;

        foreach ($details as $detail) {
            $isSection = $detail->row_type === 'section_header';
            $isFormula = $detail->row_type === 'formula';
            $isProfit = $detail->line_type === 'profit';

            $sheet->setCellValue('A' . $row, $detail->name);
            $sheet->getStyle('A' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            $sheet->getStyle('A' . $row)->getAlignment()->setIndent($isSection ? 0 : 1);

            if ($isSection) {
                $sheet->mergeCells('A' . $row . ':C' . $row);
                $sheet->getStyle('A' . $row . ':C' . $row)->getFill()
                    ->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB($lightBlue);
                $sheet->getStyle('A' . $row . ':C' . $row)->getFont()->setBold(true)->setSize(11)->setName('Arial');
                $sheet->getStyle('A' . $row . ':C' . $row)->applyFromArray($borderStyle);
                $row++;
                $alternate = false;
                continue;
            }

            // Value column
            if ($isFormula && $detail->formula_config) {
                $fc = $detail->formula_config;
                $formulaStr = $this->buildExcelFormula((array) $fc, $keyRowMap);
                if ($formulaStr) {
                    $sheet->setCellValue('B' . $row, $formulaStr);
                } else {
                    $sheet->setCellValue('B' . $row, (float) $detail->amount);
                }
                $formulaText = $this->getFormulaText((array) $fc, $details->toArray());
                $sheet->setCellValue('A' . $row, $detail->name . "\n" . $formulaText);
                $sheet->getStyle('A' . $row)->getAlignment()->setWrapText(true);
            } else {
                $sheet->setCellValue('B' . $row, (float) $detail->amount);
            }
            $sheet->getStyle('B' . $row)->getNumberFormat()->setFormatCode($currencyFormat);

            // Percentage column
            $salesRow = $keyRowMap['revenue'] ?? 4;
            $sheet->setCellValue('C' . $row, "=IF(B{$salesRow}=0,\"\",B{$row}/B{$salesRow})");
            $sheet->getStyle('C' . $row)->getNumberFormat()->setFormatCode('0.00%');
            $sheet->getStyle('C' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

            // Row styling
            $style = $sheet->getStyle('A' . $row . ':C' . $row);
            $style->applyFromArray($borderStyle);

            if ($isFormula && $isProfit) {
                $style->getFont()->setBold(true)->setSize(12)->setName('Arial');
                $style->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB($lightGreen);
            } elseif ($isFormula) {
                $style->getFont()->setBold(true)->setItalic(true)->setSize(11)->setName('Arial');
                $style->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB($lightGray);
            } elseif ($detail->row_key === 'revenue') {
                $style->getFont()->setBold(true)->setSize(11)->setName('Arial');
                $style->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB($lightGreen);
            } else {
                $style->getFont()->setSize(11)->setName('Arial');
                if ($alternate) {
                    $style->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFF8F8F8');
                }
                $alternate = !$alternate;
            }

            // -- Sub-rows: manual items --
            if ($detail->items && $detail->items->isNotEmpty()) {
                foreach ($detail->items as $item) {
                    $row++;
                    $sheet->setCellValue('A' . $row, '    • ' . $item->name);
                    $sheet->getStyle('A' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
                    $sheet->getStyle('A' . $row)->getFont()->setSize(10)->setItalic(true)->setName('Arial');
                    $sheet->getStyle('A' . $row)->getFont()->getColor()->setARGB('FF888888');
                    $sheet->setCellValue('B' . $row, (float) $item->amount);
                    $sheet->getStyle('B' . $row)->getNumberFormat()->setFormatCode($currencyFormat);
                    $sheet->getStyle('B' . $row)->getFont()->setSize(10)->setName('Arial');
                    $sheet->getStyle('A' . $row . ':C' . $row)->applyFromArray($borderStyle);
                }
            }

            $row++;
        }

        // -- Footer --
        $sheet->mergeCells('A' . $row . ':C' . $row);
        $sheet->setCellValue('A' . $row, '— نهاية التقرير —');
        $sheet->getStyle('A' . $row)->getFont()->setBold(true)->setSize(11)->setName('Arial')->getColor()->setARGB($white);
        $sheet->getStyle('A' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle('A' . $row . ':C' . $row)
            ->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB($darkBlue);
        $sheet->getRowDimension($row)->setRowHeight(24);
        $row++;

        // -- Daily Entry Details Section (separate, below main table) --
        if (!empty($detailIds)) {
            $selectedDetails = $details->whereIn('id', $detailIds)->filter(fn($d) => $d->category_id);
            if ($selectedDetails->isNotEmpty()) {
                $row++;
                $sheet->mergeCells('A' . $row . ':C' . $row);
                $sheet->setCellValue('A' . $row, 'تفاصيل القيود اليومية');
                $sheet->getStyle('A' . $row)->getFont()->setBold(true)->setSize(13)->setName('Arial')->getColor()->setARGB($darkBlue);
                $sheet->getStyle('A' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                $sheet->getRowDimension($row)->setRowHeight(28);
                $row++;

                // Column headers for details
                $sheet->setCellValue('A' . $row, 'التاريخ — البيان');
                $sheet->setCellValue('B' . $row, 'القيمة');
                $sheet->setCellValue('C' . $row, 'التصنيف');
                $sheet->getStyle('A' . $row . ':C' . $row)->getFont()->setBold(true)->setSize(10)->setName('Arial')->getColor()->setARGB($white);
                $sheet->getStyle('A' . $row . ':C' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB($darkBlue);
                $sheet->getStyle('A' . $row . ':C' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                $sheet->getStyle('A' . $row . ':C' . $row)->applyFromArray($borderStyle);
                $sheet->getRowDimension($row)->setRowHeight(22);
                $row++;

                foreach ($selectedDetails as $selDetail) {
                    $catName = $selDetail->name;
                    if (!isset($entryDetailsByCat[$catName])) continue;

                    foreach ($entryDetailsByCat[$catName] as $entry) {
                        $entryDate = $entry->entry_date ?? '';
                        $entryDesc = $entry->entry_notes ?? $entry->description ?? '—';
                        $sheet->setCellValue('A' . $row, $entryDate . ' — ' . $entryDesc);
                        $sheet->getStyle('A' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
                        $sheet->getStyle('A' . $row)->getFont()->setSize(10)->setName('Arial');
                        $sheet->setCellValue('B' . $row, (float) $entry->amount);
                        $sheet->getStyle('B' . $row)->getNumberFormat()->setFormatCode($currencyFormat);
                        $sheet->getStyle('B' . $row)->getFont()->setSize(10)->setName('Arial');
                        $sheet->setCellValue('C' . $row, $catName);
                        $sheet->getStyle('C' . $row)->getFont()->setSize(10)->setName('Arial');
                        $sheet->getStyle('A' . $row . ':C' . $row)->applyFromArray($borderStyle);
                        if ($row % 2 === 0) {
                            $sheet->getStyle('A' . $row . ':C' . $row)->getFill()
                                ->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFF8F8F8');
                        }
                        $row++;
                    }
                }
            }
        }

        // -- Print setup --
        $sheet->getPageSetup()->setOrientation(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_LANDSCAPE);
        $sheet->getPageSetup()->setPaperSize(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::PAPERSIZE_A4);
        $sheet->getPageMargins()->setTop(0.75);
        $sheet->getPageMargins()->setRight(0.5);
        $sheet->getPageMargins()->setBottom(0.75);
        $sheet->getPageMargins()->setLeft(0.5);
        $sheet->getPageSetup()->setFitToWidth(1);
        $sheet->getPageSetup()->setFitToHeight(0);

        // ========== Sheet 2: KPIs Summary ==========
        $kpiSheet = $spreadsheet->createSheet();
        $kpiSheet->setTitle('ملخص المؤشرات');
        $kpiSheet->setRightToLeft(true);

        $kpiSheet->getColumnDimension('A')->setWidth(35);
        $kpiSheet->getColumnDimension('B')->setWidth(22);
        $kpiSheet->getColumnDimension('C')->setWidth(18);

        $kpiSheet->mergeCells('A1:C1');
        $kpiSheet->setCellValue('A1', $sysName . ' | ' . $clientName);
        $kpiSheet->getStyle('A1')->getFont()->setBold(true)->setSize(14)->setName('Arial')->getColor()->setARGB($darkBlue);
        $kpiSheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $kpiSheet->getRowDimension(1)->setRowHeight(30);

        $kpiSheet->mergeCells('A2:C2');
        $kpiSheet->setCellValue('A2', 'ملخص المؤشرات المالية — ' . $monthName . ' ' . $report->year);
        $kpiSheet->getStyle('A2')->getFont()->setSize(11)->setName('Arial')->getColor()->setARGB('FF666666');
        $kpiSheet->getStyle('A2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        $kpiSheet->setCellValue('A4', 'المؤشر');
        $kpiSheet->setCellValue('B4', 'القيمة');
        $kpiSheet->setCellValue('C4', 'النسبة');
        $kpiSheet->getStyle('A4:C4')->getFont()->setBold(true)->setSize(11)->setName('Arial')->getColor()->setARGB($white);
        $kpiSheet->getStyle('A4:C4')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB($darkBlue);
        $kpiSheet->getStyle('A4:C4')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $kpiSheet->getStyle('A4:C4')->applyFromArray($borderStyle);

        $kpiData = [
            ['إجمالي المبيعات', $report->total_sales, null],
            ['إجمالي المشتريات', $report->total_purchases, $report->total_sales > 0 ? round($report->total_purchases / $report->total_sales * 100, 1) . '%' : '—'],
            ['إجمالي المصروفات', $report->total_expenses, $report->total_sales > 0 ? round($report->total_expenses / $report->total_sales * 100, 1) . '%' : '—'],
            ['صافي النقدية', $report->net_cash_profit, $report->percentages_json['net_cash_percentage'] ?? '—'],
            ['صافي الربح', $report->net_profit, $report->percentages_json['net_profit_percentage'] ?? '—'],
        ];

        $kr = 5;
        foreach ($kpiData as $i => $kpi) {
            $kpiSheet->setCellValue('A' . $kr, $kpi[0]);
            $kpiSheet->getStyle('A' . $kr)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            $kpiSheet->setCellValue('B' . $kr, (float) $kpi[1]);
            $kpiSheet->getStyle('B' . $kr)->getNumberFormat()->setFormatCode($currencyFormat);
            $kpiSheet->setCellValue('C' . $kr, $kpi[2] ?? '—');
            $kpiSheet->getStyle('C' . $kr)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

            $style = $kpiSheet->getStyle('A' . $kr . ':C' . $kr);
            $style->applyFromArray($borderStyle);
            $style->getFont()->setSize(11)->setName('Arial');

            if ($i === 4) {
                $style->getFont()->setBold(true)->setSize(12);
                $style->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB($lightGreen);
            } elseif ($i === 3) {
                $style->getFont()->setBold(true);
                $style->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB($lightGreen);
            }

            $kr++;
        }

        $kpiSheet->getPageSetup()->setOrientation(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_PORTRAIT);
        $kpiSheet->getPageSetup()->setPaperSize(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::PAPERSIZE_A4);

        // ========== Write ==========
        $writer = new Xlsx($spreadsheet);

        $filename = sprintf('تقرير مالي %s %s %s.xlsx',
            preg_replace('/[\\\\\/:*?"<>|]/', '_', $clientName),
            $monthName,
            $report->year
        );
        $filename = str_replace('  ', ' ', $filename);

        $response = new StreamedResponse(function () use ($writer) {
            $writer->save('php://output');
        });

        $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');

        return $response;
    }

    public function exportPdf(string $clientId, string $reportId, array $detailIds = []): StreamedResponse
    {
        $report = FinancialClosingReport::where('client_id', $clientId)
            ->with(['details' => fn($q) => $q->orderBy('sort_order'), 'details.items' => fn($q) => $q->orderBy('sort_order')])
            ->findOrFail($reportId);

        $details = $report->details;
        $client = Client::find($clientId);
        $clientName = $client ? $client->name : '';
        $monthName = ['', 'يناير', 'فبراير', 'مارس', 'أبريل', 'مايو', 'يونيو', 'يوليو', 'أغسطس', 'سبتمبر', 'أكتوبر', 'نوفمبر', 'ديسمبر'][$report->month] ?? $report->month;
        $sysName = config('app.name', 'ERP CostControl');

        // -- Fetch daily entry details for category-linked rows --
        $catIds = $report->details->pluck('category_id')->filter()->unique()->toArray();
        $entryDetailsByCat = collect();
        if (!empty($catIds)) {
            $dateStart = sprintf('%04d-%02d-01', $report->year, $report->month);
            $dateEnd = date('Y-m-t', strtotime($dateStart));

            $entryDetailsByCat = FinancialDailyEntryDetail::withoutGlobalScope('client')
                ->where('financial_daily_entry_details.client_id', $clientId)
                ->whereIn('financial_daily_entry_details.expense_category_id', $catIds)
                ->join('financial_daily_entries', 'financial_daily_entry_details.daily_entry_id', '=', 'financial_daily_entries.id')
                ->whereBetween('financial_daily_entries.date', [$dateStart, $dateEnd])
                ->join('financial_expense_categories', 'financial_daily_entry_details.expense_category_id', '=', 'financial_expense_categories.id')
                ->selectRaw('financial_daily_entry_details.*, financial_daily_entries.date as entry_date, financial_daily_entries.notes as entry_notes, financial_expense_categories.name as cat_name')
                ->orderBy('financial_daily_entries.date')
                ->get()
                ->groupBy('cat_name');
        }

        // -- Logo HTML (base64 for DomPDF) --
        $logoHtml = '';
        if ($client && $client->logo && Storage::disk('public')->exists($client->logo)) {
            $logoPath = Storage::disk('public')->path($client->logo);
            $logoData = base64_encode(file_get_contents($logoPath));
            $mime = mime_content_type($logoPath) ?: 'image/png';
            $logoHtml = '<img src="data:' . $mime . ';base64,' . $logoData . '" style="max-height:45px;margin-bottom:4px;">';
        }

        $html = '
        <html dir="rtl">
        <head>
            <meta charset="utf-8">
            <style>
                @page { margin: 0.8cm; }
                body { font-family: "DejaVu Sans", sans-serif; font-size: 10px; color: #333; }
                .header { text-align: center; border-bottom: 2px solid #2F5496; padding-bottom: 6px; margin-bottom: 10px; }
                .header h1 { font-size: 15px; color: #2F5496; margin: 2px 0; }
                .header .sys { font-size: 11px; color: #2F5496; font-weight: bold; }
                .header .sub { font-size: 11px; color: #666; }
                table { width: 100%; border-collapse: collapse; }
                th { background: #2F5496; color: #fff; padding: 5px 6px; text-align: center; font-size: 10px; }
                td { padding: 4px 6px; border-bottom: 1px solid #ddd; font-size: 10px; }
                .section td { background: #D6E4F0; font-weight: bold; text-align: center; }
                .formula { background: #F2F2F2; font-weight: bold; font-style: italic; }
                .profit { background: #E2EFDA; font-weight: bold; font-size: 11px; }
                .revenue { background: #E2EFDA; font-weight: bold; }
                .item-row td { font-size: 9px; font-style: italic; color: #888; }
                .entry-row td { font-size: 8px; font-style: italic; color: #999; }
                .footer { text-align: center; background: #2F5496; color: #fff; font-weight: bold; padding: 5px; margin-top: 6px; font-size: 10px; }
                .value { text-align: left; direction: ltr; }
                .percent { text-align: center; }
            </style>
        </head>
        <body>
            <div class="header">
                ' . $logoHtml . '
                <div class="sys">' . e($sysName) . ' — ' . e($clientName) . '</div>
                <h1>تقرير التقفيل المالي الشهري</h1>
                <div class="sub">' . $monthName . ' ' . $report->year . '</div>
            </div>
            <table>
                <thead>
                    <tr><th style="text-align:right">البيان</th><th style="width:100px">القيمة</th><th style="width:70px">النسبة</th></tr>
                </thead>
                <tbody>';

        $totalSales = (float) $report->total_sales;

        foreach ($details as $detail) {
            $isSection = $detail->row_type === 'section_header';
            $isFormula = $detail->row_type === 'formula';
            $isProfit = $detail->line_type === 'profit';

            $class = '';
            if ($isSection) $class = 'section';
            elseif ($isFormula && $isProfit) $class = 'profit';
            elseif ($isFormula) $class = 'formula';
            elseif ($detail->row_key === 'revenue') $class = 'revenue';

            if ($isSection) {
                $html .= '<tr class="section"><td colspan="3">' . e($detail->name) . '</td></tr>';
                continue;
            }

            $amount = number_format((float) $detail->amount, 2);
            $pct = $totalSales > 0 ? number_format((float) $detail->amount / $totalSales * 100, 1) . '%' : '—';

            $nameText = e($detail->name);
            if ($detail->formula_text) {
                $nameText .= ' <span style="font-size:9px;color:#999">' . e($detail->formula_text) . '</span>';
            }

            $html .= '<tr class="' . $class . '">';
            $html .= '<td style="text-align:right">' . $nameText . '</td>';
            $html .= '<td class="value">' . $amount . '</td>';
            $html .= '<td class="percent">' . $pct . '</td>';
            $html .= '</tr>';

            // Sub-rows: manual items
            if ($detail->items && $detail->items->isNotEmpty()) {
                foreach ($detail->items as $item) {
                    $itemAmount = number_format((float) $item->amount, 2);
                    $html .= '<tr class="item-row">';
                    $html .= '<td style="text-align:right;padding-right:15px">• ' . e($item->name) . '</td>';
                    $html .= '<td class="value">' . $itemAmount . '</td>';
                    $html .= '<td></td>';
                    $html .= '</tr>';
                }
            }

        }

        // -- Daily Entry Details Section (separate, below main table) --
        if (!empty($detailIds)) {
            $selectedDetails = $details->whereIn('id', $detailIds)->filter(fn($d) => $d->category_id);
            if ($selectedDetails->isNotEmpty()) {
                $html .= '<div style="margin-top:12px;"><h3 style="color:#2F5496;font-size:12px;text-align:center;border-bottom:1px solid #2F5496;padding-bottom:4px;">تفاصيل القيود اليومية</h3>';
                $html .= '<table><thead><tr><th style="text-align:right">التاريخ — البيان</th><th style="width:100px">القيمة</th><th style="width:80px">التصنيف</th></tr></thead><tbody>';

                foreach ($selectedDetails as $selDetail) {
                    $catName = $selDetail->name;
                    if (!isset($entryDetailsByCat[$catName])) continue;

                    foreach ($entryDetailsByCat[$catName] as $entry) {
                        $entryDate = $entry->entry_date ?? '';
                        $entryDesc = $entry->entry_notes ?? $entry->description ?? '—';
                        $entryAmt = number_format((float) $entry->amount, 2);
                        $html .= '<tr>';
                        $html .= '<td style="text-align:right">' . e($entryDate) . ' — ' . e($entryDesc) . '</td>';
                        $html .= '<td class="value">' . $entryAmt . '</td>';
                        $html .= '<td style="text-align:center">' . e($catName) . '</td>';
                        $html .= '</tr>';
                    }
                }

                $html .= '</tbody></table></div>';
            }
        }

        $html .= '
                </tbody>
            </table>
            <div class="footer">— نهاية التقرير —</div>
        </body>
        </html>';

        $pdf = Pdf::loadHTML($html);
        $pdf->setPaper('A4', 'landscape');

        $filename = sprintf('تقرير مالي %s %s %s.pdf', preg_replace('/[\\\\\/:*?"<>|]/', '_', $clientName), $monthName, $report->year);
        $filename = str_replace('  ', ' ', $filename);

        return response()->streamDownload(function () use ($pdf) {
            echo $pdf->output();
        }, $filename, ['Content-Type' => 'application/pdf']);
    }

    private function buildExcelFormula(array $fc, array $keyRowMap): string
    {
        $type = $fc['type'] ?? 'sum';

        if ($type === 'custom') {
            $expression = $fc['expression'] ?? '';
            // Replace row keys (including UUID-based) with Excel cell references
            $keys = array_keys($keyRowMap);
            usort($keys, fn($a, $b) => strlen($b) - strlen($a));
            foreach ($keys as $key) {
                $expression = str_replace($key, 'B' . $keyRowMap[$key], $expression);
            }
            return '=' . $expression;
        }

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
