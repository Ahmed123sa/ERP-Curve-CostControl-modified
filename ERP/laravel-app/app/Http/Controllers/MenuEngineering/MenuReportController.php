<?php
namespace App\Http\Controllers\MenuEngineering;

use App\Http\Controllers\Controller;
use App\Models\MenuEngineering\MenuRecipe;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class MenuReportController extends Controller
{
    public function summary(Request $request): JsonResponse
    {
        $clientId = $request->user()->current_client_id;
        $branchId = $request->branch_id;
        $menuId = $request->menu_id;

        $query = MenuRecipe::where('client_id', $clientId);
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
                'name'     => $name,
                'avg_cost' => count($items) > 0 ? round($catCost / count($items), 2) : 0,
                'items'    => $items,
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
}
