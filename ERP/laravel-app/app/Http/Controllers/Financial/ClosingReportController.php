<?php

namespace App\Http\Controllers\Financial;

use App\Http\Controllers\Controller;
use App\Services\Financial\ClosingReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ClosingReportController extends Controller
{
    public function __construct(private ClosingReportService $service) {}

    public function index(Request $request): JsonResponse
    {
        $clientId = $request->user()->current_client_id;
        $month = $request->query('month');
        $year = $request->query('year');

        if ($month && $year) {
            $reports = $this->service->list($clientId, (int) $month, (int) $year);
        } else {
            $reports = $this->service->list($clientId);
        }

        return response()->json(['reports' => $reports]);
    }

    public function generate(Request $request): JsonResponse
    {
        $clientId = $request->user()->current_client_id;

        $data = $request->validate([
            'month' => 'required|integer|between:1,12',
            'year' => 'required|integer|min:2020',
        ]);

        $report = $this->service->generate($clientId, (int) $data['month'], (int) $data['year']);

        return response()->json(['report' => $report, 'message' => 'تم توليد تقرير التقفيل']);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $clientId = $request->user()->current_client_id;

        $report = \App\Models\Financial\FinancialClosingReport::where('client_id', $clientId)
            ->with(['details' => fn($q) => $q->orderBy('sort_order'), 'details.items'])
            ->with('approvedByUser:id,name', 'closedByUser:id,name')
            ->findOrFail($id);

        // Attach formula_text to each detail
        $details = $report->details;
        $allDetails = $details->toArray();
        foreach ($report->details as $detail) {
            if ($detail->formula_config) {
                $detail->formula_text = $this->service->getFormulaText((array) $detail->formula_config, $allDetails);
            }
        }

        return response()->json(['report' => $report]);
    }

    public function updateDetail(Request $request, string $detailId): JsonResponse
    {
        $clientId = $request->user()->current_client_id;

        $data = $request->validate([
            'amount' => 'nullable|numeric|min:0',
            'name'   => 'nullable|string|max:255',
        ]);

        $detail = $this->service->updateDetail($clientId, $detailId, $data);

        return response()->json(['detail' => $detail, 'message' => 'تم التحديث']);
    }

    public function resetDetailToAuto(Request $request, string $detailId): JsonResponse
    {
        $clientId = $request->user()->current_client_id;

        $detail = $this->service->resetDetailToAuto($clientId, $detailId);

        return response()->json(['detail' => $detail, 'message' => 'تم إعادة التعيين التلقائي']);
    }

    public function addDetailItem(Request $request, string $detailId): JsonResponse
    {
        $clientId = $request->user()->current_client_id;

        $data = $request->validate([
            'name'       => 'required|string|max:255',
            'amount'     => 'nullable|numeric|min:0',
            'sort_order' => 'nullable|integer|min:0',
        ]);

        $item = $this->service->addDetailItem($clientId, $detailId, $data);

        return response()->json(['item' => $item, 'message' => 'تمت الإضافة'], 201);
    }

    public function deleteDetailItem(Request $request, string $itemId): JsonResponse
    {
        $clientId = $request->user()->current_client_id;

        $this->service->deleteDetailItem($clientId, $itemId);

        return response()->json(['message' => 'تم الحذف']);
    }

    public function exportExcel(Request $request, string $id)
    {
        $clientId = $request->user()->current_client_id;

        $detailIds = $request->query('detail_ids');
        $detailIds = $detailIds ? explode(',', $detailIds) : [];

        return $this->service->exportExcel($clientId, $id, $detailIds);
    }

    public function exportPdf(Request $request, string $id)
    {
        $clientId = $request->user()->current_client_id;

        $detailIds = $request->query('detail_ids');
        $detailIds = $detailIds ? explode(',', $detailIds) : [];

        return $this->service->exportPdf($clientId, $id, $detailIds);
    }

    public function addDetail(Request $request, string $reportId): JsonResponse
    {
        $clientId = $request->user()->current_client_id;

        $data = $request->validate([
            'name'      => 'required|string|max:255',
            'amount'    => 'nullable|numeric|min:0',
            'line_type' => 'nullable|in:expense,purchase,revenue',
        ]);

        $detail = $this->service->addCustomDetail($clientId, $reportId, $data);

        return response()->json(['detail' => $detail, 'message' => 'تمت الإضافة'], 201);
    }

    public function deleteDetail(Request $request, string $detailId): JsonResponse
    {
        $clientId = $request->user()->current_client_id;

        $this->service->deleteCustomDetail($clientId, $detailId);

        return response()->json(['message' => 'تم الحذف']);
    }

    public function updateFormula(Request $request, string $detailId): JsonResponse
    {
        $clientId = $request->user()->current_client_id;

        $data = $request->validate([
            'formula' => 'required|array',
        ]);

        $detail = $this->service->updateDetailFormula($clientId, $detailId, $data['formula']);

        return response()->json(['detail' => $detail, 'message' => 'تم تحديث المعادلة']);
    }

    // ====== Daily Entries for Detail Breakdown ======

    public function getDetailEntries(Request $request, string $detailId): JsonResponse
    {
        $clientId = $request->user()->current_client_id;

        $entries = $this->service->getDetailEntries($clientId, $detailId);

        return response()->json(['entries' => $entries]);
    }

    // ====== Link Advances & Salaries ======

    public function linkAdvances(Request $request, string $id): JsonResponse
    {
        $clientId = $request->user()->current_client_id;

        $data = $this->service->getLinkAdvances($clientId, $id);

        return response()->json($data);
    }

    public function linkSalaries(Request $request, string $id): JsonResponse
    {
        $clientId = $request->user()->current_client_id;

        $data = $this->service->getLinkSalaries($clientId, $id);

        return response()->json($data);
    }

    public function applyLinkValue(Request $request, string $detailId): JsonResponse
    {
        $clientId = $request->user()->current_client_id;

        $data = $request->validate([
            'value' => 'required|numeric|min:0',
        ]);

        $detail = $this->service->applyLinkValue($clientId, $detailId, $data['value']);

        return response()->json(['detail' => $detail, 'message' => 'تم تطبيق القيمة']);
    }

    // ====== Approval Workflow ======

    public function approve(Request $request, string $id): JsonResponse
    {
        $clientId = $request->user()->current_client_id;

        $report = $this->service->approve($clientId, $id);

        return response()->json(['report' => $report, 'message' => 'تم اعتماد التقرير']);
    }

    public function close(Request $request, string $id): JsonResponse
    {
        $clientId = $request->user()->current_client_id;

        $report = $this->service->close($clientId, $id);

        return response()->json(['report' => $report, 'message' => 'تم إغلاق التقرير']);
    }

    public function reopen(Request $request, string $id): JsonResponse
    {
        $clientId = $request->user()->current_client_id;

        $report = $this->service->reopen($clientId, $id);

        return response()->json(['report' => $report, 'message' => 'تم إعادة فتح التقرير']);
    }
}
