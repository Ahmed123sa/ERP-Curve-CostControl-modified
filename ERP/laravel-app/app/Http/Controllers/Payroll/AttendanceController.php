<?php

namespace App\Http\Controllers\Payroll;

use App\Http\Controllers\Controller;
use App\Services\Payroll\AttendanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AttendanceController extends Controller
{
    public function __construct(private AttendanceService $service) {}

    public function index(Request $request): JsonResponse
    {
        $clientId = $request->user()->current_client_id;
        $month = (int) $request->query('month', now()->month);
        $year = (int) $request->query('year', now()->year);
        $employeeId = $request->query('employee_id');

        $records = $this->service->list($clientId, $month, $year, $employeeId);
        return response()->json(['records' => $records]);
    }

    public function store(Request $request): JsonResponse
    {
        $clientId = $request->user()->current_client_id;

        $data = $request->validate([
            'employee_id' => 'required|uuid',
            'date' => 'required|date',
            'shift_start' => 'required|date_format:H:i',
            'shift_end' => 'required|date_format:H:i',
            'notes' => 'nullable|string|max:500',
        ]);

        $record = $this->service->store($clientId, $data);
        return response()->json(['record' => $record, 'message' => 'تم تسجيل الحضور']);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $clientId = $request->user()->current_client_id;
        $this->service->destroy($clientId, $id);
        return response()->json(['message' => 'تم حذف تسجيل الحضور']);
    }
}
