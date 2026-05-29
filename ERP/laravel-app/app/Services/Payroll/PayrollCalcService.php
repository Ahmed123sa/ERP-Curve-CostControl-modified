<?php

namespace App\Services\Payroll;

use App\Models\Financial\EmployeeAdvance;
use App\Models\Payroll\PayrollMonthly;
use App\Models\Payroll\PayrollMonthlyDetail;
use App\Models\Payroll\PayrollEmployee;
use Illuminate\Support\Facades\DB;

class PayrollCalcService
{
    public function calculate(string $clientId, int $month, int $year): PayrollMonthly
    {
        return DB::transaction(function () use ($clientId, $month, $year) {
            $daysInMonth = (int) date('t', strtotime("{$year}-{$month}-01"));

            $employees = PayrollEmployee::where('client_id', $clientId)
                ->where('is_active', true)
                ->get();

            $payroll = PayrollMonthly::updateOrCreate(
                ['client_id' => $clientId, 'month' => $month, 'year' => $year],
                ['status' => 'draft']
            );

            foreach ($employees as $employee) {
                $records = \App\Models\Payroll\AttendanceRecord::where('client_id', $clientId)
                    ->where('employee_id', $employee->id)
                    ->whereYear('date', $year)
                    ->whereMonth('date', $month)
                    ->get();

                $existingDetail = PayrollMonthlyDetail::where('payroll_id', $payroll->id)
                    ->where('employee_id', $employee->id)
                    ->first();

                // Keep existing work_days & rest_days_taken if recalculating; otherwise use defaults
                $workDays = $existingDetail?->work_days ?? $records->where('total_hours', '>', 0)->count();
                $restDaysTaken = $existingDetail?->rest_days_taken ?? 0;

                $overtimeHours = $records->sum('overtime_minutes') / 60;
                $doubleShiftDays = $records->where('is_double_shift', true)->count();

                // New logic: rest_day_ot = max(4 - rest_days_taken, 0)
                $restDayOTDays = max(4 - $restDaysTaken, 0);
                // absence = month_days - work_days - min(rest_days_taken, 4)
                $absenceDays = max($daysInMonth - $workDays - min($restDaysTaken, 4), 0);

                $advanceAmount = 0;
                if ($employee->financial_employee_id) {
                    $advanceAmount = (float) EmployeeAdvance::where('client_id', $clientId)
                        ->where('employee_id', $employee->financial_employee_id)
                        ->whereYear('date', $year)
                        ->whereMonth('date', $month)
                        ->sum('amount');
                }

                $dailyWage = $employee->daily_wage > 0 ? $employee->daily_wage : round($employee->base_salary / 30, 2);
                $hourlyWage = $employee->hourly_wage > 0 ? $employee->hourly_wage : round($dailyWage / $employee->shift_hours, 2);

                $absenceAmount = $absenceDays * $dailyWage;
                $overtimeAmount = $overtimeHours * $hourlyWage;
                $restDayOTAmount = $restDayOTDays * $dailyWage;
                $doubleShiftAmount = $doubleShiftDays * $dailyWage;

                $detail = PayrollMonthlyDetail::updateOrCreate(
                    [
                        'payroll_id' => $payroll->id,
                        'employee_id' => $employee->id,
                    ],
                    [
                        'client_id' => $clientId,
                        'base_salary_snapshot' => $employee->base_salary,
                        'daily_wage_snapshot' => $dailyWage,
                        'hourly_wage_snapshot' => $hourlyWage,
                        'work_days' => $workDays,
                        'rest_days_taken' => $restDaysTaken,
                        'rest_day_ot_days' => $restDayOTDays,
                        'rest_day_ot_amount' => round($restDayOTAmount, 2),
                        'absence_days' => $absenceDays,
                        'absence_amount' => round($absenceAmount, 2),
                        'overtime_hours' => round($overtimeHours, 2),
                        'overtime_amount' => round($overtimeAmount, 2),
                        'double_shift_days' => $doubleShiftDays,
                        'double_shift_amount' => round($doubleShiftAmount, 2),
                        'advance_amount' => round($advanceAmount, 2),
                    ]
                );

                $detail->load('bonusItems');
                $bonusTotal = $detail->bonusItems->sum('amount');

                $totalDeductions = $absenceAmount + $advanceAmount;
                $baseAmount = $workDays * $dailyWage;
                $netSalary = round($baseAmount + $overtimeAmount + $restDayOTAmount + $doubleShiftAmount + $bonusTotal - $totalDeductions, 2);

                $detail->update([
                    'bonus_total' => $bonusTotal,
                    'total_deductions' => round($totalDeductions, 2),
                    'net_salary' => $netSalary,
                ]);
            }

            return $payroll->fresh()->load('details.employee', 'details.bonusItems');
        });
    }

    public function list(string $clientId): array
    {
        return PayrollMonthly::where('client_id', $clientId)
            ->with('details.employee', 'details.bonusItems')
            ->orderBy('year', 'desc')
            ->orderBy('month', 'desc')
            ->get()
            ->toArray();
    }

    public function show(string $clientId, string $id): PayrollMonthly
    {
        return PayrollMonthly::where('client_id', $clientId)
            ->with('details.employee', 'details.bonusItems')
            ->findOrFail($id);
    }

    public function approve(string $clientId, string $id): PayrollMonthly
    {
        $payroll = PayrollMonthly::where('client_id', $clientId)->findOrFail($id);
        $payroll->update(['status' => 'approved']);
        return $payroll->fresh()->load('details.employee', 'details.bonusItems');
    }

    public function updateBonus(string $clientId, string $detailId, array $bonusItems): PayrollMonthlyDetail
    {
        $detail = PayrollMonthlyDetail::where('client_id', $clientId)->findOrFail($detailId);

        return DB::transaction(function () use ($detail, $clientId, $bonusItems) {
            $detail->bonusItems()->delete();
            foreach ($bonusItems as $item) {
                if (!empty($item['name']) && ($item['amount'] ?? 0) > 0) {
                    $detail->bonusItems()->create([
                        'client_id' => $clientId,
                        'name' => $item['name'],
                        'amount' => $item['amount'],
                    ]);
                }
            }
            $detail->load('bonusItems');
            $bonusTotal = $detail->bonusItems->sum('amount');

            $dailyWage = $detail->daily_wage_snapshot;
            $baseAmount = $detail->work_days * $dailyWage;
            $totalDeductions = $detail->absence_amount + $detail->advance_amount;
            $netSalary = round($baseAmount + $detail->overtime_amount + $detail->rest_day_ot_amount
                + $detail->double_shift_amount + $bonusTotal - $totalDeductions, 2);

            $detail->update([
                'bonus_total' => $bonusTotal,
                'total_deductions' => round($totalDeductions, 2),
                'net_salary' => $netSalary,
            ]);
            return $detail->fresh()->load('bonusItems', 'employee');
        });
    }

    public function updateCell(string $clientId, string $detailId, string $field, $value): PayrollMonthlyDetail
    {
        $detail = PayrollMonthlyDetail::where('client_id', $clientId)->findOrFail($detailId);

        $allowedFields = [
            'work_days', 'rest_days_taken', 'absence_days', 'absence_amount',
            'overtime_hours', 'overtime_amount', 'rest_day_ot_days', 'rest_day_ot_amount',
            'double_shift_days', 'double_shift_amount', 'advance_amount', 'bonus_total',
        ];

        abort_unless(in_array($field, $allowedFields), 422, 'الحقل غير قابل للتعديل');

        return DB::transaction(function () use ($detail, $field, $value) {
            $updates = [$field => $value];

            // Auto-compute derived fields when rest_days_taken or work_days changes
            if ($field === 'rest_days_taken') {
                $monthDays = (int) date('t', strtotime("{$detail->payroll->year}-{$detail->payroll->month}-01"));
                $restDayOtDays = max(4 - (int) $value, 0);
                $absenceDays = max($monthDays - $detail->work_days - min((int) $value, 4), 0);
                $updates['rest_day_ot_days'] = $restDayOtDays;
                $updates['rest_day_ot_amount'] = round($restDayOtDays * $detail->daily_wage_snapshot, 2);
                $updates['absence_days'] = $absenceDays;
                $updates['absence_amount'] = round($absenceDays * $detail->daily_wage_snapshot, 2);
            } elseif ($field === 'work_days') {
                $monthDays = (int) date('t', strtotime("{$detail->payroll->year}-{$detail->payroll->month}-01"));
                $absenceDays = max($monthDays - (int) $value - min($detail->rest_days_taken, 4), 0);
                $updates['absence_days'] = $absenceDays;
                $updates['absence_amount'] = round($absenceDays * $detail->daily_wage_snapshot, 2);
            }

            $detail->update($updates);

            // Recalculate totals
            $totalDeductions = $detail->absence_amount + $detail->advance_amount;
            $baseAmount = $detail->work_days * $detail->daily_wage_snapshot;
            $netSalary = round($baseAmount + $detail->overtime_amount + $detail->rest_day_ot_amount
                + $detail->double_shift_amount + $detail->bonus_total - $totalDeductions, 2);

            $detail->update([
                'total_deductions' => round($totalDeductions, 2),
                'net_salary' => $netSalary,
            ]);

            return $detail->fresh()->load('bonusItems', 'employee');
        });
    }
}
