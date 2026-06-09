<?php

namespace App\Services\Payroll;

use App\Models\Financial\EmployeeAdvance;
use App\Models\Payroll\PayrollMonthly;
use App\Models\Payroll\PayrollMonthlyDetail;
use App\Models\Payroll\PayrollEmployee;
use Illuminate\Support\Facades\DB;

class PayrollCalcService
{
    public function calculate(string $clientId, int $month, int $year, ?int $salaryBaseDays = null): PayrollMonthly
    {
        return DB::transaction(function () use ($clientId, $month, $year, $salaryBaseDays) {
            $daysInMonth = (int) date('t', strtotime("{$year}-{$month}-01"));

            $employees = PayrollEmployee::where('client_id', $clientId)
                ->where('is_active', true)
                ->get();

            $payroll = PayrollMonthly::updateOrCreate(
                ['client_id' => $clientId, 'month' => $month, 'year' => $year],
                [
                    'status' => 'draft',
                    'salary_base_days' => $salaryBaseDays ?? 30,
                ]
            );

            $baseDays = $payroll->salary_base_days ?? 30;

            foreach ($employees as $employee) {
                $records = \App\Models\Payroll\AttendanceRecord::where('client_id', $clientId)
                    ->where('employee_id', $employee->id)
                    ->whereYear('date', $year)
                    ->whereMonth('date', $month)
                    ->get();

                $existingDetail = PayrollMonthlyDetail::where('payroll_id', $payroll->id)
                    ->where('employee_id', $employee->id)
                    ->first();

                $workDays = $existingDetail?->work_days ?? $records->where('total_hours', '>', 0)->count();
                $absenceDays = $daysInMonth - $workDays;

                $overtimeHours = $records->sum('overtime_minutes') / 60;
                $doubleShiftDays = $records->where('is_double_shift', true)->count();

                $skipRestDays = $existingDetail?->rest_days_taken ?? false;

                if ($skipRestDays) {
                    $restDayOTDays = 0;
                    $effectiveAbsence = 0;
                } else {
                    $allowedRestDays = $workDays * 4 / 30;
                    $restDayOTDays = max(0, (int) round($allowedRestDays - $absenceDays));
                    $effectiveAbsence = max(0, $absenceDays - (int) round($allowedRestDays));
                }

                $advanceAmount = 0;
                if ($employee->financial_employee_id) {
                    $advanceAmount = (float) EmployeeAdvance::where('client_id', $clientId)
                        ->where('employee_id', $employee->financial_employee_id)
                        ->whereYear('date', $year)
                        ->whereMonth('date', $month)
                        ->sum('amount');
                }

                $dailyWage = round($employee->base_salary / $baseDays, 2);
                $hourlyWage = round($dailyWage / $employee->shift_hours, 2);

                $absenceAmount = $effectiveAbsence * $dailyWage;
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
                        'rest_day_ot_days' => $restDayOTDays,
                        'rest_day_ot_amount' => round($restDayOTAmount, 2),
                        'absence_days' => $absenceDays,
                        'absence_amount' => round($absenceAmount, 2),
                        'overtime_hours' => round($overtimeHours, 2),
                        'overtime_amount' => round($overtimeAmount, 2),
                        'double_shift_days' => $doubleShiftDays,
                        'double_shift_amount' => round($doubleShiftAmount, 2),
                        'advance_amount' => round($advanceAmount, 2),
                        'rest_days_taken' => $existingDetail?->rest_days_taken ?? 0,
                    ]
                );

                $detail->load('bonusItems');
                $bonusTotal = $detail->bonusItems->sum('amount');

                if ($skipRestDays) {
                    $totalDeductions = $advanceAmount;
                    $baseAmount = $workDays * $dailyWage;
                } else {
                    $totalDeductions = $absenceAmount + $advanceAmount;
                    $baseAmount = $baseDays * $dailyWage;
                }
                $netSalary = round($baseAmount + $overtimeAmount + $restDayOTAmount + $doubleShiftAmount + $bonusTotal - $totalDeductions, 2);

                $detail->update([
                    'bonus_total' => $bonusTotal,
                    'total_deductions' => round($totalDeductions, 2),
                    'net_salary' => $netSalary,
                ]);
            }

        return $payroll->fresh()->load('client', 'details.employee', 'details.bonusItems');
        });
    }

    public function list(string $clientId): array
    {
        return PayrollMonthly::where('client_id', $clientId)
            ->with('client', 'details.employee', 'details.bonusItems')
            ->orderBy('year', 'desc')
            ->orderBy('month', 'desc')
            ->get()
            ->toArray();
    }

    public function show(string $clientId, string $id): PayrollMonthly
    {
        return PayrollMonthly::where('client_id', $clientId)
            ->with('client', 'details.employee', 'details.bonusItems')
            ->findOrFail($id);
    }

    public function approve(string $clientId, string $id): PayrollMonthly
    {
        $payroll = PayrollMonthly::where('client_id', $clientId)->findOrFail($id);
        $payroll->update(['status' => 'approved']);
        return $payroll->fresh()->load('client', 'details.employee', 'details.bonusItems');
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

            if ($detail->rest_days_taken) {
                $baseAmount = $detail->work_days * $dailyWage;
                $totalDeductions = $detail->advance_amount;
            } else {
                $baseDays = $detail->payroll->salary_base_days ?? 30;
                $baseAmount = $baseDays * $dailyWage;
                $totalDeductions = $detail->absence_amount + $detail->advance_amount;
            }
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
            'work_days', 'absence_days', 'absence_amount',
            'overtime_hours', 'overtime_amount', 'rest_day_ot_days', 'rest_day_ot_amount',
            'double_shift_days', 'double_shift_amount', 'advance_amount', 'bonus_total',
            'rest_days_taken',
        ];

        abort_unless(in_array($field, $allowedFields), 422, 'الحقل غير قابل للتعديل');

        return DB::transaction(function () use ($detail, $field, $value) {
            $updates = [$field => $value];

            // Auto-compute derived fields when work_days, absence_days, or rest_days_taken changes
            if ($field === 'work_days') {
                $monthDays = (int) date('t', strtotime("{$detail->payroll->year}-{$detail->payroll->month}-01"));
                $absenceDays = $monthDays - (int) $value;

                if ($detail->rest_days_taken) {
                    $restDayOTDays = 0;
                    $effectiveAbsence = 0;
                } else {
                    $allowedRestDays = (int) $value * 4 / 30;
                    $restDayOTDays = max(0, (int) round($allowedRestDays - $absenceDays));
                    $effectiveAbsence = max(0, $absenceDays - (int) round($allowedRestDays));
                }

                $updates['absence_days'] = $absenceDays;
                $updates['rest_day_ot_days'] = $restDayOTDays;
                $updates['rest_day_ot_amount'] = round($restDayOTDays * $detail->daily_wage_snapshot, 2);
                $updates['absence_amount'] = round($effectiveAbsence * $detail->daily_wage_snapshot, 2);
            } elseif ($field === 'absence_days') {
                if ($detail->rest_days_taken) {
                    $restDayOTDays = 0;
                    $effectiveAbsence = 0;
                } else {
                    $allowedRestDays = $detail->work_days * 4 / 30;
                    $restDayOTDays = max(0, (int) round($allowedRestDays - (int)$value));
                    $effectiveAbsence = max(0, (int) $value - (int) round($allowedRestDays));
                }
                $updates['rest_day_ot_days'] = $restDayOTDays;
                $updates['rest_day_ot_amount'] = round($restDayOTDays * $detail->daily_wage_snapshot, 2);
                $updates['absence_amount'] = round($effectiveAbsence * $detail->daily_wage_snapshot, 2);
            } elseif ($field === 'rest_days_taken') {
                $updates['rest_days_taken'] = (int) $value;
                $monthDays = (int) date('t', strtotime("{$detail->payroll->year}-{$detail->payroll->month}-01"));
                $absenceDays = $monthDays - $detail->work_days;

                if ((int) $value) {
                    $restDayOTDays = 0;
                    $effectiveAbsence = 0;
                } else {
                    $allowedRestDays = $detail->work_days * 4 / 30;
                    $restDayOTDays = max(0, (int) round($allowedRestDays - $absenceDays));
                    $effectiveAbsence = max(0, $absenceDays - (int) round($allowedRestDays));
                }

                $updates['rest_day_ot_days'] = $restDayOTDays;
                $updates['rest_day_ot_amount'] = round($restDayOTDays * $detail->daily_wage_snapshot, 2);
                $updates['absence_amount'] = round($effectiveAbsence * $detail->daily_wage_snapshot, 2);
            }

            $detail->update($updates);
            $detail->refresh();

            // Recalculate totals
            if ($detail->rest_days_taken) {
                $totalDeductions = $detail->advance_amount;
                $baseAmount = $detail->work_days * $detail->daily_wage_snapshot;
            } else {
                $totalDeductions = $detail->absence_amount + $detail->advance_amount;
                $baseDays = $detail->payroll->salary_base_days ?? 30;
                $baseAmount = $baseDays * $detail->daily_wage_snapshot;
            }
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
