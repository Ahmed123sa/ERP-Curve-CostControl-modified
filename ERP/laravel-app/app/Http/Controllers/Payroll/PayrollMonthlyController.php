<?php

namespace App\Http\Controllers\Payroll;

use App\Http\Controllers\Controller;
use App\Services\Payroll\PayrollCalcService;
use App\Services\Payroll\PayslipExportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PayrollMonthlyController extends Controller
{
    public function __construct(
        private PayrollCalcService $calcService,
        private PayslipExportService $exportService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $clientId = $request->user()->current_client_id;
        $payrolls = $this->calcService->list($clientId);
        return response()->json(['payrolls' => $payrolls]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $clientId = $request->user()->current_client_id;
        $payroll = $this->calcService->show($clientId, $id);
        return response()->json(['payroll' => $payroll]);
    }

    public function calculate(Request $request): JsonResponse
    {
        $clientId = $request->user()->current_client_id;

        $data = $request->validate([
            'month' => 'required|integer|between:1,12',
            'year' => 'required|integer|min:2020',
        ]);

        $payroll = $this->calcService->calculate($clientId, (int) $data['month'], (int) $data['year']);
        return response()->json(['payroll' => $payroll, 'message' => 'تم حساب الرواتب']);
    }

    public function approve(Request $request, string $id): JsonResponse
    {
        $clientId = $request->user()->current_client_id;
        $payroll = $this->calcService->approve($clientId, $id);
        return response()->json(['payroll' => $payroll, 'message' => 'تم اعتماد الرواتب']);
    }

    public function updateBonus(Request $request, string $detailId): JsonResponse
    {
        $clientId = $request->user()->current_client_id;

        $data = $request->validate([
            'bonus_items' => 'nullable|array',
            'bonus_items.*.name' => 'required|string|max:255',
            'bonus_items.*.amount' => 'required|numeric|min:0',
        ]);

        $detail = $this->calcService->updateBonus($clientId, $detailId, $data['bonus_items'] ?? []);
        return response()->json(['detail' => $detail, 'message' => 'تم تحديث المكافآت']);
    }

    public function exportExcel(Request $request, string $id)
    {
        $clientId = $request->user()->current_client_id;
        return $this->exportService->exportExcel($clientId, $id);
    }

    public function exportPayslipPdf(Request $request, string $id, string $employeeId)
    {
        $clientId = $request->user()->current_client_id;
        return $this->exportService->exportPayslipPdf($clientId, $id, $employeeId);
    }
}
