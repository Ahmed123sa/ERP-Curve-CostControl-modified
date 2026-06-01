<?php

namespace App\Http\Controllers\Payroll;

use App\Http\Controllers\Controller;
use App\Services\Payroll\PayrollCalcService;
use App\Services\Payroll\PayslipExportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Models\Payroll\PayrollMonthly;

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
            'salary_base_days' => 'nullable|integer|in:28,29,30,31',
        ]);

        $payroll = $this->calcService->calculate(
            $clientId,
            (int) $data['month'],
            (int) $data['year'],
            $data['salary_base_days'] ?? null,
        );
        return response()->json(['payroll' => $payroll, 'message' => 'تم حساب الرواتب']);
    }

    public function approve(Request $request, string $id): JsonResponse
    {
        $clientId = $request->user()->current_client_id;
        $payroll = $this->calcService->approve($clientId, $id);
        return response()->json(['payroll' => $payroll, 'message' => 'تم اعتماد الرواتب']);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $clientId = $request->user()->current_client_id;
        $payroll = PayrollMonthly::where('client_id', $clientId)->where('id', $id)->firstOrFail();
        if ($payroll->status !== 'draft') {
            return response()->json(['message' => 'لا يمكن حذف رواتب معتمدة'], 422);
        }
        $payroll->delete();
        return response()->json(['message' => 'تم حذف المسودة']);
    }

    public function updateBaseDays(Request $request, string $id): JsonResponse
    {
        $clientId = $request->user()->current_client_id;
        $data = $request->validate(['salary_base_days' => 'required|integer|in:28,29,30,31']);
        $payroll = PayrollMonthly::where('client_id', $clientId)->findOrFail($id);
        $payroll->update(['salary_base_days' => $data['salary_base_days']]);
        return response()->json(['payroll' => $payroll, 'message' => 'تم تحديث أساس الشهر']);
    }

    public function updateCell(Request $request, string $detailId): JsonResponse
    {
        $clientId = $request->user()->current_client_id;

        $data = $request->validate([
            'field' => 'required|string',
            'value' => 'required|numeric|min:0',
        ]);

        $detail = $this->calcService->updateCell($clientId, $detailId, $data['field'], $data['value']);
        return response()->json(['detail' => $detail, 'message' => 'تم تحديث الخلية']);
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
