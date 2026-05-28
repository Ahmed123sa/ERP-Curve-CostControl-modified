<?php

namespace App\Http\Controllers\Financial;

use App\Http\Controllers\Controller;
use App\Services\Financial\AdvanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdvanceController extends Controller
{
    public function __construct(private AdvanceService $service) {}

    public function employees(Request $request): JsonResponse
    {
        $clientId = $request->user()->current_client_id;
        $employees = $this->service->employees($clientId);

        return response()->json(['employees' => $employees]);
    }

    public function storeEmployee(Request $request): JsonResponse
    {
        $clientId = $request->user()->current_client_id;

        $data = $request->validate([
            'name' => 'required|string|max:255',
        ]);

        $employee = $this->service->storeEmployee($clientId, $data['name']);

        return response()->json(['employee' => $employee, 'message' => 'تم إضافة الموظف']);
    }

    public function updateEmployee(Request $request, string $id): JsonResponse
    {
        $clientId = $request->user()->current_client_id;

        $data = $request->validate([
            'name' => 'required|string|max:255',
        ]);

        $employee = $this->service->updateEmployee($clientId, $id, $data['name']);

        return response()->json(['employee' => $employee, 'message' => 'تم تحديث بيانات الموظف']);
    }

    public function index(Request $request): JsonResponse
    {
        $clientId = $request->user()->current_client_id;
        $month = $request->query('month', now()->format('Y-m'));

        $data = $this->service->list($clientId, $month);

        return response()->json($data);
    }

    public function store(Request $request): JsonResponse
    {
        $clientId = $request->user()->current_client_id;

        $data = $request->validate([
            'employee_id' => 'required|uuid',
            'date' => 'required|date',
            'amount' => 'required|numeric|min:0',
            'notes' => 'nullable|string|max:500',
        ]);

        $advance = $this->service->store($clientId, $data);

        return response()->json(['advance' => $advance, 'message' => 'تم حفظ السلفة']);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $clientId = $request->user()->current_client_id;
        $this->service->destroy($clientId, $id);

        return response()->json(['message' => 'تم حذف السلفة']);
    }

    public function exportExcel(Request $request): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $clientId = $request->user()->current_client_id;
        $month = $request->query('month', now()->format('Y-m'));

        return $this->service->exportExcel($clientId, $month);
    }
}
