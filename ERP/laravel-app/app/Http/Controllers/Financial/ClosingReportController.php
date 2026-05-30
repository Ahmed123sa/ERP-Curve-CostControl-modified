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
            ->findOrFail($id);

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

        return $this->service->exportExcel($clientId, $id);
    }

    public function exportPdf(Request $request, string $id)
    {
        $clientId = $request->user()->current_client_id;

        $report = \App\Models\Financial\FinancialClosingReport::where('client_id', $clientId)
            ->with('details')
            ->findOrFail($id);

        // TODO: implement PDF export
        return response()->json(['message' => 'جاري التصدير...']);
    }
}
