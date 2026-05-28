<?php

namespace App\Services\Financial;

use App\Models\Financial\FinancialEmployee;
use App\Models\Financial\EmployeeAdvance;
use Illuminate\Support\Facades\DB;

class AdvanceService
{
    public function employees(string $clientId): array
    {
        return FinancialEmployee::where('client_id', $clientId)
            ->orderBy('name')
            ->get()
            ->toArray();
    }

    public function storeEmployee(string $clientId, string $name): FinancialEmployee
    {
        return FinancialEmployee::create([
            'client_id' => $clientId,
            'name' => $name,
        ]);
    }

    public function updateEmployee(string $clientId, string $id, string $name): FinancialEmployee
    {
        $employee = FinancialEmployee::where('client_id', $clientId)->findOrFail($id);
        $employee->update(['name' => $name]);
        return $employee;
    }

    public function list(string $clientId, string $month): array
    {
        [$year, $monthNum] = explode('-', $month);

        $employees = FinancialEmployee::where('client_id', $clientId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        $advances = EmployeeAdvance::where('client_id', $clientId)
            ->whereYear('date', $year)
            ->whereMonth('date', $monthNum)
            ->get();

        $daysInMonth = (int) date('t', strtotime($month . '-01'));

        // Build matrix: employee_id x day -> amount
        $matrix = [];
        foreach ($employees as $emp) {
            $matrix[$emp->id] = [
                'employee' => $emp,
                'days' => array_fill(1, $daysInMonth, 0),
                'total' => 0,
            ];
        }

        foreach ($advances as $adv) {
            $day = (int) $adv->date->format('d');
            if (isset($matrix[$adv->employee_id])) {
                $matrix[$adv->employee_id]['days'][$day] = (float) $adv->amount;
                $matrix[$adv->employee_id]['total'] += (float) $adv->amount;
            }
        }

        return [
            'month' => $month,
            'days_in_month' => $daysInMonth,
            'employees' => $employees->toArray(),
            'matrix' => $matrix,
        ];
    }

    public function store(string $clientId, array $data): EmployeeAdvance
    {
        return DB::transaction(function () use ($clientId, $data) {
            // Delete existing advance for same employee+date
            EmployeeAdvance::where('client_id', $clientId)
                ->where('employee_id', $data['employee_id'])
                ->where('date', $data['date'])
                ->delete();

            if (($data['amount'] ?? 0) > 0) {
                return EmployeeAdvance::create([
                    'client_id' => $clientId,
                    'employee_id' => $data['employee_id'],
                    'date' => $data['date'],
                    'amount' => $data['amount'],
                    'notes' => $data['notes'] ?? null,
                ]);
            }

            return null;
        });
    }

    public function destroy(string $clientId, string $id): bool
    {
        $advance = EmployeeAdvance::where('client_id', $clientId)->findOrFail($id);
        return $advance->delete();
    }
}
