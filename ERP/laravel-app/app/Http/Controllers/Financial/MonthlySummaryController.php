<?php

namespace App\Http\Controllers\Financial;

use App\Http\Controllers\Controller;
use App\Services\Financial\MonthlySummaryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MonthlySummaryController extends Controller
{
    public function __construct(private MonthlySummaryService $service) {}

    public function index(Request $request): JsonResponse
    {
        $clientId = $request->user()->current_client_id;
        $month = $request->query('month');
        $year = $request->query('year');

        if ($month && $year) {
            $summaries = $this->service->list($clientId, (int) $month, (int) $year);
        } else {
            $summaries = $this->service->list($clientId);
        }

        return response()->json(['summaries' => $summaries]);
    }

    public function generate(Request $request): JsonResponse
    {
        $clientId = $request->user()->current_client_id;

        $data = $request->validate([
            'month' => 'required|integer|between:1,12',
            'year' => 'required|integer|min:2020',
        ]);

        $summary = $this->service->generate($clientId, (int) $data['month'], (int) $data['year']);

        return response()->json(['summary' => $summary, 'message' => 'تم توليد التجميع الشهري']);
    }

    public function finalize(Request $request, string $id): JsonResponse
    {
        $clientId = $request->user()->current_client_id;
        $summary = $this->service->finalize($clientId, $id);

        return response()->json(['summary' => $summary, 'message' => 'تم اعتماد التجميع الشهري']);
    }
}
