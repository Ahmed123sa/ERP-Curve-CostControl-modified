<?php

namespace App\Services\Financial;

use App\Models\Financial\FinancialDailyEntry;
use App\Models\Financial\FinancialDailyEntryDetail;
use App\Models\Financial\FinancialMonthlySummary;
use App\Models\Financial\FinancialMonthlySummaryDetail;
use Illuminate\Support\Facades\DB;

class MonthlySummaryService
{
    public function list(string $clientId, ?int $month = null, ?int $year = null): array
    {
        $query = FinancialMonthlySummary::where('client_id', $clientId)
            ->with('details.category');

        if ($month && $year) {
            $query->where('month', $month)->where('year', $year);
        }

        return $query->orderBy('year', 'desc')->orderBy('month', 'desc')->get()->toArray();
    }

    public function generate(string $clientId, int $month, int $year): FinancialMonthlySummary
    {
        return DB::transaction(function () use ($clientId, $month, $year) {
            $dateStart = sprintf('%04d-%02d-01', $year, $month);
            $dateEnd = date('Y-m-t', strtotime($dateStart));

            $entries = FinancialDailyEntry::where('client_id', $clientId)
                ->whereBetween('date', [$dateStart, $dateEnd])
                ->get();

            $totalSales = $entries->sum('total_sales');
            $totalExpenses = $entries->sum('total_expenses');
            $netTotal = $totalSales - $totalExpenses;

            // Category totals
            $categoryTotals = FinancialDailyEntryDetail::whereIn('daily_entry_id', $entries->pluck('id'))
                ->selectRaw('expense_category_id, SUM(amount) as total')
                ->groupBy('expense_category_id')
                ->pluck('total', 'expense_category_id');

            $summary = FinancialMonthlySummary::updateOrCreate(
                ['client_id' => $clientId, 'month' => $month, 'year' => $year],
                [
                    'total_sales' => $totalSales,
                    'total_expenses' => $totalExpenses,
                    'net_total' => $netTotal,
                    'status' => 'draft',
                ]
            );

            $summary->details()->delete();

            foreach ($categoryTotals as $categoryId => $total) {
                $summary->details()->create([
                    'client_id' => $clientId,
                    'expense_category_id' => $categoryId,
                    'total_amount' => $total,
                ]);
            }

            $summary->load('details.category');

            return $summary;
        });
    }

    public function finalize(string $clientId, string $id): FinancialMonthlySummary
    {
        $summary = FinancialMonthlySummary::where('client_id', $clientId)
            ->findOrFail($id);

        $summary->update(['status' => 'finalized']);

        return $summary;
    }
}
