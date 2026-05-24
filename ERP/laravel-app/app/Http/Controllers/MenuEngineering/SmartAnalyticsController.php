<?php
namespace App\Http\Controllers\MenuEngineering;

use App\Http\Controllers\Controller;
use App\Services\MenuEngineering\SmartAnalyticsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SmartAnalyticsController extends Controller
{
    public function __construct(
        private SmartAnalyticsService $analytics,
    ) {}

    // GET /api/menu-engineering/analytics/inventory-alerts
    public function inventoryAlerts(Request $request): JsonResponse
    {
        $clientId = $request->user()->current_client_id;
        $threshold = (float) ($request->threshold ?? 20);
        $warehouseIds = $request->warehouse_ids ? explode(',', $request->warehouse_ids) : null;
        $result = $this->analytics->inventoryAlerts($clientId, $threshold, $warehouseIds);
        return response()->json($result);
    }

    // GET /api/menu-engineering/analytics/top-purchases
    public function topPurchases(Request $request): JsonResponse
    {
        $clientId = $request->user()->current_client_id;
        $result = $this->analytics->topPurchases(
            $clientId,
            $request->from,
            $request->to,
            (int) ($request->limit ?? 10),
            $request->warehouse_id,
        );
        return response()->json($result);
    }

    // GET /api/menu-engineering/analytics/price-changes
    public function priceChanges(Request $request): JsonResponse
    {
        $clientId = $request->user()->current_client_id;
        $result = $this->analytics->priceChanges(
            $clientId,
            (float) ($request->threshold ?? 10),
            $request->from,
            $request->to,
            (int) ($request->limit ?? 50),
        );
        return response()->json($result);
    }

    // GET /api/menu-engineering/analytics/cost-impact
    public function costImpact(Request $request): JsonResponse
    {
        $clientId = $request->user()->current_client_id;
        $result = $this->analytics->costImpact(
            $clientId,
            $request->from,
            $request->to,
        );
        return response()->json($result);
    }

    // GET /api/menu-engineering/analytics/cost-contribution
    public function costContribution(Request $request): JsonResponse
    {
        $clientId = $request->user()->current_client_id;
        $result = $this->analytics->costContribution(
            $clientId,
            $request->menu_id,
            $request->branch_id,
        );
        return response()->json($result);
    }

    // GET /api/menu-engineering/analytics/stock-value
    public function stockValue(Request $request): JsonResponse
    {
        $clientId = $request->user()->current_client_id;
        $result = $this->analytics->stockValueSummary($clientId);
        return response()->json($result);
    }
}
