<?php

namespace App\Services\Financial;

use App\Models\Financial\FinancialClosingReport;
use App\Models\Financial\FinancialClosingReportDetail;
use App\Models\Financial\FinancialDailyEntry;
use App\Models\Financial\FinancialDailyEntryDetail;
use Illuminate\Support\Facades\DB;

class ClosingReportService
{
    public function list(string $clientId, ?int $month = null, ?int $year = null): array
    {
        $query = FinancialClosingReport::where('client_id', $clientId)
            ->with('details');

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

            $totalSales = $entries->sum('total_sales');

            // Category totals from daily details
            $categoryTotals = FinancialDailyEntryDetail::withoutGlobalScope('client')
                ->where('financial_daily_entry_details.client_id', $clientId)
                ->whereIn('daily_entry_id', $entries->pluck('id'))
                ->join('financial_expense_categories', 'financial_daily_entry_details.expense_category_id', '=', 'financial_expense_categories.id')
                ->selectRaw('financial_expense_categories.name, financial_expense_categories.code, SUM(amount) as total')
                ->groupBy('financial_expense_categories.name', 'financial_expense_categories.code')
                ->orderBy('financial_expense_categories.sort_order')
                ->get();

            // Map categories to line types
            $purchaseCategories = ['bar_purchases', 'kitchen_purchases', 'shisha_purchases'];
            $expenseCategories = []; // all others

            $totalPurchases = 0;
            $totalExpenses = 0;
            $lines = [];

            foreach ($categoryTotals as $cat) {
                $amount = (float) $cat->total;
                $percentage = $totalSales > 0 ? round($amount / $totalSales * 100, 4) : 0;

                if (in_array($cat->code, $purchaseCategories)) {
                    $lineType = 'purchase';
                    $totalPurchases += $amount;
                } else {
                    $lineType = 'expense';
                    $totalExpenses += $amount;
                }

                $lines[] = [
                    'line_type' => $lineType,
                    'name' => $cat->name,
                    'amount' => $amount,
                    'percentage' => $percentage,
                ];
            }

            $totalExpenses = $totalPurchases + $totalExpenses; // purchases are part of expenses
            $netCashProfit = $totalSales - $totalExpenses;
            $netProfit = $totalSales - $totalPurchases; // simplified: revenue - COGS

            $netCashPercentage = $totalSales > 0 ? round($netCashProfit / $totalSales * 100, 4) : 0;
            $netProfitPercentage = $totalSales > 0 ? round($netProfit / $totalSales * 100, 4) : 0;

            // Create report
            $report = FinancialClosingReport::updateOrCreate(
                ['client_id' => $clientId, 'month' => $month, 'year' => $year],
                [
                    'total_sales' => $totalSales,
                    'total_purchases' => $totalPurchases,
                    'total_expenses' => $totalExpenses,
                    'net_cash_profit' => $netCashProfit,
                    'net_profit' => $netProfit,
                    'percentages_json' => [
                        'net_cash_percentage' => $netCashPercentage,
                        'net_profit_percentage' => $netProfitPercentage,
                    ],
                    'status' => 'draft',
                ]
            );

            $report->details()->delete();

            // Add revenue line
            $report->details()->create([
                'client_id' => $clientId,
                'line_type' => 'revenue',
                'name' => 'إجمالي مبيعات',
                'amount' => $totalSales,
                'percentage' => 100,
                'sort_order' => 0,
            ]);

            // Add category lines
            $sortOrder = 1;
            foreach ($lines as $line) {
                $report->details()->create([
                    'client_id' => $clientId,
                    'line_type' => $line['line_type'],
                    'name' => $line['name'],
                    'amount' => $line['amount'],
                    'percentage' => $line['percentage'],
                    'sort_order' => $sortOrder++,
                ]);
            }

            // Add total purchases line
            $report->details()->create([
                'client_id' => $clientId,
                'line_type' => 'purchase',
                'name' => 'إجمالي مشتريات',
                'amount' => $totalPurchases,
                'percentage' => $totalSales > 0 ? round($totalPurchases / $totalSales * 100, 4) : 0,
                'sort_order' => $sortOrder++,
            ]);

            // Add total expenses line
            $report->details()->create([
                'client_id' => $clientId,
                'line_type' => 'expense',
                'name' => 'إجمالي مصروفات',
                'amount' => $totalExpenses,
                'percentage' => $totalSales > 0 ? round($totalExpenses / $totalSales * 100, 4) : 0,
                'sort_order' => $sortOrder++,
            ]);

            // Add net cash profit line
            $report->details()->create([
                'client_id' => $clientId,
                'line_type' => 'profit',
                'name' => 'صافي نقدية (ربح نقدي)',
                'amount' => $netCashProfit,
                'percentage' => $netCashPercentage,
                'sort_order' => $sortOrder++,
            ]);

            // Add net profit line
            $report->details()->create([
                'client_id' => $clientId,
                'line_type' => 'profit',
                'name' => 'ربح صافي',
                'amount' => $netProfit,
                'percentage' => $netProfitPercentage,
                'sort_order' => $sortOrder++,
            ]);

            $report->load('details');

            return $report;
        });
    }
}
