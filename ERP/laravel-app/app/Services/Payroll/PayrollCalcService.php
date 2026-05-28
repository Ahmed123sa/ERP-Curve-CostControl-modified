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

                $workDays = $records->where('total_hours', '>', 0)->count();
                $absenceDays = $daysInMonth - $workDays;
                $overtimeHours = $records->sum('overtime_minutes') / 60;

                $restDayOT = 0;
                if ($workDays > 0) {
                    if ($workDays >= $daysInMonth) {
                        $restDayOT = max(4 - ($daysInMonth - $workDays), 0);
                    } else {
                        $restDayOT = max((int) ($workDays / 14) * 2 - ($daysInMonth - $workDays), 0);
                    }
                }

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
                $restDayOTAmount = $restDayOT * $dailyWage;

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
                        'absence_days' => $absenceDays,
                        'absence_amount' => round($absenceAmount, 2),
                        'overtime_hours' => round($overtimeHours, 2),
                        'overtime_amount' => round($overtimeAmount, 2),
                        'rest_day_ot_days' => $restDayOT,
                        'rest_day_ot_amount' => round($restDayOTAmount, 2),
                        'advance_amount' => round($advanceAmount, 2),
                    ]
                );

                // Load bonus items to compute bonus_total and net_salary
                $detail->load('bonusItems');
                $bonusTotal = $detail->bonusItems->sum('amount');

                $totalDeductions = $absenceAmount + $advanceAmount;
                $baseAmount = $workDays * $dailyWage;
                $netSalary = round($baseAmount + $overtimeAmount + $restDayOTAmount + $bonusTotal - $totalDeductions, 2);

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
            $netSalary = round($baseAmount + $detail->overtime_amount + $detail->rest_day_ot_amount + $bonusTotal - $totalDeductions, 2);

            $detail->update([
                'bonus_total' => $bonusTotal,
                'total_deductions' => round($totalDeductions, 2),
                'net_salary' => $netSalary,
            ]);

            return $detail->fresh()->load('bonusItems', 'employee');
        });
    }
}
