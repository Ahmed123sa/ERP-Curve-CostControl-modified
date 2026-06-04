<?php

namespace App\Services\Payroll;

use App\Models\Payroll\AttendanceRecord;
use App\Models\Payroll\PayrollEmployee;
use Illuminate\Support\Facades\DB;

class AttendanceService
{
    public function list(string $clientId, int $month, int $year, ?string $employeeId = null): array
    {
        $query = AttendanceRecord::where('client_id', $clientId)
            ->with('employee:id,name')
            ->whereYear('date', $year)
            ->whereMonth('date', $month);

        if ($employeeId) {
            $query->where('employee_id', $employeeId);
        }

        return $query->orderBy('date')->orderBy('employee_id')->get()->toArray();
    }

    public function store(string $clientId, array $data): AttendanceRecord
    {
        $employee = PayrollEmployee::where('client_id', $clientId)->findOrFail($data['employee_id']);

        $shiftStart = $data['shift_start'];
        $shiftEnd = $data['shift_end'];

        $start = strtotime($shiftStart);
        $end = strtotime($shiftEnd);
        if ($end <= $start) {
            $end += 86400;
        }
        $totalHours = ($end - $start) / 3600;

        $shiftHours = (float) $employee->shift_hours;
        $diffHours = $totalHours - $shiftHours;
        $isDoubleShift = $diffHours >= ($shiftHours - 0.5);
        $overtimeMinutes = $isDoubleShift ? 0 : round($diffHours * 60, 2);

        return DB::transaction(function () use ($clientId, $data, $totalHours, $overtimeMinutes, $isDoubleShift) {
            AttendanceRecord::where('client_id', $clientId)
                ->where('employee_id', $data['employee_id'])
                ->where('date', $data['date'])
                ->delete();

            return AttendanceRecord::create([
                'client_id' => $clientId,
                'employee_id' => $data['employee_id'],
                'date' => $data['date'],
                'shift_start' => $data['shift_start'],
                'shift_end' => $data['shift_end'],
                'total_hours' => round($totalHours, 2),
                'overtime_minutes' => round($overtimeMinutes, 2),
                'is_double_shift' => $isDoubleShift,
                'notes' => $data['notes'] ?? null,
            ]);
        });
    }

    public function destroy(string $clientId, string $id): bool
    {
        $record = AttendanceRecord::where('client_id', $clientId)->findOrFail($id);
        return $record->delete();
    }
}
