<?php
namespace App\Http\Controllers\MenuEngineering;

use App\Http\Controllers\Controller;
use App\Models\MenuEngineering\MenuRecipe;
use App\Services\MenuEngineering\MenuExportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MenuReportController extends Controller
{
    public function __construct(
        private MenuExportService $export,
    ) {}

    public function summary(Request $request): JsonResponse
    {
        $clientId = $request->user()->current_client_id;
        $branchId = $request->branch_id;
        $menuId = $request->menu_id;

        $query = MenuRecipe::where('client_id', $clientId)
            ->where('status', 'active')
            ->where('exclude_from_menu', false);
        if ($branchId) {
            $query->where('branch_id', $branchId);
        }
        if ($menuId) {
            $query->where('menu_id', $menuId);
        }

        $recipes = $query->get();
        $grouped = [];
        $overallTotalCost = 0;
        $overallTotalPrice = 0;

        foreach ($recipes as $r) {
            $tc = $r->total_cost;
            $sp = (float) ($r->selling_price ?? 0);
            $cp = $sp > 0 ? round(($tc / $sp) * 100, 2) : 0;
            $cat = $r->category ?? 'أخرى';

            $grouped[$cat][] = [
                'id'            => $r->id,
                'name'          => $r->name,
                'total_cost'    => $tc,
                'selling_price' => $sp,
                'cost_pct'      => $cp,
                'status'        => $r->status,
            ];

            $overallTotalCost += $tc;
            $overallTotalPrice += $sp;
        }

        $categories = [];
        foreach ($grouped as $name => $items) {
            $catCost = array_sum(array_column($items, 'total_cost'));
            $catPrice = array_sum(array_column($items, 'selling_price'));
            $categories[] = [
                'name'       => $name,
                'total_cost' => round($catCost, 2),
                'cost_pct'   => $catPrice > 0 ? round(($catCost / $catPrice) * 100, 2) : 0,
                'items'      => $items,
            ];
        }

        Log::info('MenuReport::summary', [
            'client_id' => $clientId,
            'branch_id' => $branchId,
            'menu_id' => $menuId,
            'recipes_count' => count($recipes),
        ]);

        return response()->json([
            'overall' => [
                'total_cost'         => round($overallTotalCost, 2),
                'total_selling_price'=> round($overallTotalPrice, 2),
                'overall_cost_pct'   => $overallTotalPrice > 0 ? round(($overallTotalCost / $overallTotalPrice) * 100, 2) : 0,
            ],
            'categories' => $categories,
        ]);
    }

    public function exportExcel(Request $request): StreamedResponse
    {
        $clientId = $request->user()->current_client_id;
        $client = $request->user()->clients()->findOrFail($clientId);
        return $this->export->streamReportExcel(
            $clientId,
            $request->branch_id,
            $request->menu_id,
            $client,
        );
    }

    public function exportPdf(Request $request): StreamedResponse
    {
        $clientId = $request->user()->current_client_id;
        $client = $request->user()->clients()->findOrFail($clientId);
        return $this->export->streamReportPdf(
            $clientId,
            $request->branch_id,
            $request->menu_id,
            $client,
        );
    }
}
