<?php

namespace App\Services\Financial;

use App\Models\Financial\FinancialDailyEntry;
use App\Models\Financial\FinancialDailyEntryDetail;
use Illuminate\Support\Facades\DB;

class DailyEntryService
{
    public function list(string $clientId, string $month): array
    {
        [$year, $monthNum] = explode('-', $month);

        $entries = FinancialDailyEntry::where('client_id', $clientId)
            ->whereYear('date', $year)
            ->whereMonth('date', $monthNum)
            ->with('details.category')
            ->orderBy('date')
            ->get();

        $categories = \App\Models\Financial\FinancialExpenseCategory::where('client_id', $clientId)
            ->orWhereNull('client_id')
            ->orderBy('sort_order')
            ->get(['id', 'name', 'code']);

        return [
            'entries' => $entries,
            'categories' => $categories,
        ];
    }

    public function store(string $clientId, array $data): FinancialDailyEntry
    {
        return DB::transaction(function () use ($clientId, $data) {
            $totalExpenses = collect($data['details'] ?? [])->sum('amount');
            $netDaily = ($data['total_sales'] ?? 0) - $totalExpenses;

            $entry = FinancialDailyEntry::updateOrCreate(
                ['client_id' => $clientId, 'date' => $data['date']],
                [
                    'total_sales' => $data['total_sales'] ?? 0,
                    'total_expenses' => $totalExpenses,
                    'net_daily' => $netDaily,
                    'notes' => $data['notes'] ?? null,
                ]
            );

            $entry->details()->delete();

            if (!empty($data['details'])) {
                foreach ($data['details'] as $detail) {
                    if (($detail['amount'] ?? 0) > 0) {
                        $entry->details()->create([
                            'client_id' => $clientId,
                            'expense_category_id' => $detail['expense_category_id'],
                            'amount' => $detail['amount'],
                            'description' => $detail['description'] ?? null,
                        ]);
                    }
                }

                $entry->load('details.category');
            }

            return $entry;
        });
    }

    public function destroy(string $clientId, string $id): bool
    {
        $entry = FinancialDailyEntry::where('client_id', $clientId)
            ->findOrFail($id);

        return $entry->delete();
    }
}
