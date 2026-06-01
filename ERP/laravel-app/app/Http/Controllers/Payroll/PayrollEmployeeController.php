<?php

namespace App\Http\Controllers\Payroll;

use App\Http\Controllers\Controller;
use App\Models\Financial\FinancialEmployee;
use App\Models\Payroll\PayrollEmployee;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PayrollEmployeeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $clientId = $request->user()->current_client_id;
        $employees = PayrollEmployee::where('client_id', $clientId)
            ->orderBy('name')
            ->get()
            ->toArray();
        return response()->json(['employees' => $employees]);
    }

    public function store(Request $request): JsonResponse
    {
        $clientId = $request->user()->current_client_id;

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'job_title' => 'nullable|string|max:255',
            'base_salary' => 'required|numeric|min:0',
            'shift_hours' => 'nullable|numeric|min:1|max:24',
        ]);

        return DB::transaction(function () use ($clientId, $data) {
            $shiftHours = $data['shift_hours'] ?? 9;
            $dailyWage = round($data['base_salary'] / 30, 2);
            $hourlyWage = round($dailyWage / $shiftHours, 2);

            // Auto-create/update financial_employee for advances sync
            $financialEmployee = FinancialEmployee::where('client_id', $clientId)
                ->where('name', $data['name'])
                ->first();

            if (!$financialEmployee) {
                $financialEmployee = FinancialEmployee::create([
                    'client_id' => $clientId,
                    'name' => $data['name'],
                ]);
            }

            $employee = PayrollEmployee::create([
                'client_id' => $clientId,
                'name' => $data['name'],
                'job_title' => $data['job_title'] ?? null,
                'base_salary' => $data['base_salary'],
                'shift_hours' => $shiftHours,
                'daily_wage' => $dailyWage,
                'hourly_wage' => $hourlyWage,
                'financial_employee_id' => $financialEmployee->id,
            ]);

            return response()->json(['employee' => $employee, 'message' => 'تم إضافة الموظف']);
        });
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $clientId = $request->user()->current_client_id;

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'job_title' => 'nullable|string|max:255',
            'base_salary' => 'required|numeric|min:0',
            'shift_hours' => 'nullable|numeric|min:1|max:24',
        ]);

        $employee = PayrollEmployee::where('client_id', $clientId)->findOrFail($id);
        $shiftHours = $data['shift_hours'] ?? $employee->shift_hours;
        $dailyWage = round($data['base_salary'] / 30, 2);
        $hourlyWage = round($dailyWage / $shiftHours, 2);

        $employee->update([
            'name' => $data['name'],
            'job_title' => $data['job_title'] ?? null,
            'base_salary' => $data['base_salary'],
            'shift_hours' => $shiftHours,
            'daily_wage' => $dailyWage,
            'hourly_wage' => $hourlyWage,
        ]);

        // Sync name change to linked FinancialEmployee
        if ($employee->financial_employee_id) {
            FinancialEmployee::where('client_id', $clientId)
                ->where('id', $employee->financial_employee_id)
                ->update(['name' => $data['name']]);
        }

        return response()->json(['employee' => $employee, 'message' => 'تم تحديث بيانات الموظف']);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $clientId = $request->user()->current_client_id;
        $employee = PayrollEmployee::where('client_id', $clientId)->findOrFail($id);
        $employee->delete();
        return response()->json(['message' => 'تم حذف الموظف']);
    }
}
