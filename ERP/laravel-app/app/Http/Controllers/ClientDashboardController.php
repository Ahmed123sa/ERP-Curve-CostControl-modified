<?php
namespace App\Http\Controllers;

use App\Models\MonthlyClosing;
use App\Models\StockLedger;
use App\Models\Warehouse;
use App\Models\Item;
use App\Models\DispatchOrder;
use App\Models\MenuEngineering\MenuEngineeringMenu;
use App\Models\MenuEngineering\MenuRecipe;
use App\Services\CostCalculationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ClientDashboardController extends Controller
{
    public function __construct(private CostCalculationService $calc) {}

    private function getClientId(Request $request): ?string
    {
        return $request->user()->current_client_id;
    }

    public function kpis(Request $request): JsonResponse
    {
        $clientId = $this->getClientId($request);
        if (! $clientId) {
            return response()->json(['stock_value' => 0, 'monthly_purchases' => 0, 'total_diffs' => 0, 'warehouse_count' => 0]);
        }

        $month = $request->month ?? now()->format('Y-m');
        $start = now()->parse($month . '-01')->toDateString();
        $end = now()->parse($month . '-01')->endOfMonth()->toDateString();

        $warehouseIds = Warehouse::where('client_id', $clientId)
            ->whereIn('type', ['main', 'sub'])->pluck('id');

        $stockValue = (float) MonthlyClosing::where('client_id', $clientId)
            ->whereIn('warehouse_id', $warehouseIds)
            ->where('month', $month)
            ->selectRaw('SUM(COALESCE(closing_qty_actual, 0) * avg_cost) as val')
            ->value('val');

        $monthlyPurchases = (float) StockLedger::where('client_id', $clientId)
            ->whereBetween('date', [$start, $end])
            ->where('voucher_type', 'purchase')->where('movement_type', 'in')
            ->sum('total_cost');

        $totalDiffs = (float) MonthlyClosing::where('client_id', $clientId)
            ->whereIn('warehouse_id', $warehouseIds)
            ->where('month', $month)->sum('diff_value');

        $warehouseCount = $warehouseIds->count();

        $prevMonth = now()->parse($month)->subMonth()->format('Y-m');
        $prevPurchases = (float) StockLedger::where('client_id', $clientId)
            ->whereBetween('date', [
                now()->parse($prevMonth . '-01')->toDateString(),
                now()->parse($prevMonth . '-01')->endOfMonth()->toDateString(),
            ])->where('voucher_type', 'purchase')->where('movement_type', 'in')
            ->sum('total_cost');

        return response()->json([
            'stock_value' => $stockValue,
            'monthly_purchases' => $monthlyPurchases,
            'total_diffs' => $totalDiffs,
            'warehouse_count' => $warehouseCount,
            'purchases_change' => $prevPurchases > 0
                ? round((($monthlyPurchases - $prevPurchases) / $prevPurchases) * 100, 1) : 0,
        ]);
    }

    public function stockDistribution(Request $request): JsonResponse
    {
        $clientId = $this->getClientId($request);
        if (! $clientId) {
            return response()->json([]);
        }

        $month = $request->month ?? now()->format('Y-m');

        $data = Warehouse::where('client_id', $clientId)
            ->where('is_active', true)
            ->get(['id', 'name'])
            ->map(function ($wh) use ($clientId, $month) {
                $val = (float) MonthlyClosing::where('client_id', $clientId)
                    ->where('warehouse_id', $wh->id)
                    ->where('month', $month)
                    ->selectRaw('SUM(COALESCE(closing_qty_actual, 0) * avg_cost) as val')
                    ->value('val');
                return ['name' => $wh->name, 'value' => round($val, 2)];
            })->filter(fn ($r) => $r['value'] > 0)->sortByDesc('value')->values();

        return response()->json($data);
    }

    public function monthlyTrend(Request $request): JsonResponse
    {
        $clientId = $this->getClientId($request);
        if (! $clientId) {
            return response()->json([]);
        }

        $warehouseIds = Warehouse::where('client_id', $clientId)
            ->whereIn('type', ['main', 'sub'])->pluck('id');

        $months = [];
        for ($i = 5; $i >= 0; $i--) {
            $m = now()->subMonths($i)->format('Y-m');
            $start = now()->parse($m . '-01')->toDateString();
            $end = now()->parse($m . '-01')->endOfMonth()->toDateString();
            $months[] = [
                'month' => $m,
                'purchases' => (float) StockLedger::where('client_id', $clientId)
                    ->whereBetween('date', [$start, $end])
                    ->where('voucher_type', 'purchase')->where('movement_type', 'in')
                    ->sum('total_cost'),
                'diffs' => (float) MonthlyClosing::where('client_id', $clientId)
                    ->whereIn('warehouse_id', $warehouseIds)->where('month', $m)
                    ->sum('diff_value'),
            ];
        }

        return response()->json($months);
    }

    public function topDiffItems(Request $request): JsonResponse
    {
        $clientId = $this->getClientId($request);
        if (! $clientId) {
            return response()->json([]);
        }

        $month = $request->month ?? now()->format('Y-m');
        $warehouseIds = Warehouse::where('client_id', $clientId)
            ->whereIn('type', ['main', 'sub'])->pluck('id');

        $data = MonthlyClosing::where('client_id', $clientId)
            ->whereIn('warehouse_id', $warehouseIds)
            ->where('month', $month)
            ->selectRaw('item_id, SUM(diff_value) as total_diff')
            ->groupBy('item_id')
            ->orderByRaw('ABS(SUM(diff_value)) DESC')
            ->limit(10)
            ->get()
            ->map(function ($row) {
                $item = Item::find($row->item_id);
                return [
                    'item_name' => $item?->name ?? 'صنف',
                    'diff_value' => round((float) $row->total_diff, 2),
                ];
            });

        return response()->json($data);
    }

    public function trends(Request $request): JsonResponse
    {
        return $this->monthlyTrend($request);
    }

    public function alerts(Request $request): JsonResponse
    {
        $clientId = $this->getClientId($request);
        if (! $clientId) {
            return response()->json([]);
        }

        $items = Item::where('client_id', $clientId)
            ->where('is_active', true)
            ->whereNotNull('min_stock_level')
            ->get(['id', 'name', 'unit', 'min_stock_level']);

        $warehouseIds = Warehouse::where('client_id', $clientId)
            ->where('is_active', true)->where('type', '!=', 'branch')->pluck('id');

        $outOfStock = [];
        $critical = [];
        $warning = [];

        foreach ($items as $item) {
            $total = 0;
            foreach ($warehouseIds as $whId) {
                $total += $this->calc->currentStock($clientId, $whId, $item->id);
            }
            $min = (float) $item->min_stock_level;
            if ($total <= 0) {
                $outOfStock[] = ['item_name' => $item->name, 'unit' => $item->unit, 'current_qty' => $total, 'min_stock_level' => $min];
            } elseif ($total < $min) {
                $critical[] = ['item_name' => $item->name, 'unit' => $item->unit, 'current_qty' => $total, 'min_stock_level' => $min];
            } elseif ($min > 0 && $total <= $min * 1.5) {
                $warning[] = ['item_name' => $item->name, 'unit' => $item->unit, 'current_qty' => $total, 'min_stock_level' => $min];
            }
        }

        return response()->json([
            'out_of_stock' => $outOfStock,
            'critical' => $critical,
            'warning' => $warning,
            'summary' => [
                'out_of_stock_count' => count($outOfStock),
                'critical_count' => count($critical),
                'warning_count' => count($warning),
            ],
        ]);
    }

    public function menuSnapshot(Request $request): JsonResponse
    {
        $clientId = $this->getClientId($request);
        if (! $clientId) {
            return response()->json([]);
        }

        $recipes = MenuRecipe::where('client_id', $clientId)
            ->where('status', 'active')
            ->where('exclude_from_menu', false)
            ->where('selling_price', '>', 0)
            ->with('items')
            ->get();

        $result = [];
        foreach ($recipes as $recipe) {
            $totalCost = (float) $recipe->items->sum('line_total');
            $sellingPrice = (float) $recipe->selling_price;
            $fcPct = $sellingPrice > 0 ? round(($totalCost / $sellingPrice) * 100, 1) : 0;
            $result[] = [
                'name' => $recipe->name,
                'total_cost' => $totalCost,
                'selling_price' => $sellingPrice,
                'fc_pct' => $fcPct,
                'profit_margin' => round(100 - $fcPct, 1),
            ];
        }

        usort($result, fn($a, $b) => $a['fc_pct'] <=> $b['fc_pct']);

        return response()->json([
            'most_profitable' => array_slice($result, 0, 5),
            'least_profitable' => array_slice(array_reverse($result), 0, 5),
            'total_recipes' => count($result),
        ]);
    }

    public function recentActivity(Request $request): JsonResponse
    {
        $clientId = $this->getClientId($request);
        if (! $clientId) {
            return response()->json([]);
        }

        $ledger = StockLedger::where('client_id', $clientId)
            ->orderByDesc('created_at')
            ->limit(5)
            ->get()
            ->map(function ($row) {
                $item = Item::find($row->item_id);
                return [
                    'date' => $row->date,
                    'voucher_type' => $row->voucher_type,
                    'movement_type' => $row->movement_type,
                    'item_name' => $item?->name ?? '—',
                    'qty' => $row->qty,
                    'total_cost' => $row->total_cost,
                ];
            });

        return response()->json($ledger);
    }

    public function warehouses(Request $request): JsonResponse
    {
        $clientId = $this->getClientId($request);
        if (! $clientId) {
            return response()->json([]);
        }

        $list = Warehouse::where('client_id', $clientId)
            ->where('is_active', true)
            ->get(['id', 'name', 'type']);

        return response()->json($list);
    }

    public function currentStock(Request $request): JsonResponse
    {
        $clientId = $this->getClientId($request);
        $warehouseId = $request->warehouse_id;

        if (! $clientId || ! $warehouseId) {
            return response()->json([]);
        }

        $items = Item::where('client_id', $clientId)->where('is_active', true)->orderBy('sort_order')->get();
        $stock = [];

        foreach ($items as $item) {
            $qty = $this->calc->currentStock($clientId, $warehouseId, $item->id);
            if ($qty != 0) {
                $avg = $this->calc->weightedAverageCost($clientId, $warehouseId, $item->id);
                $stock[] = [
                    'id' => $item->id,
                    'name' => $item->name,
                    'unit' => $item->unit,
                    'qty' => $qty,
                    'avg_cost' => $avg,
                ];
            }
        }

        return response()->json($stock);
    }

    public function warehouseSummary(Request $request): JsonResponse
    {
        $clientId = $this->getClientId($request);
        if (! $clientId) {
            return response()->json([]);
        }

        $month = $request->month ?? now()->format('Y-m');

        $warehouses = Warehouse::where('client_id', $clientId)
            ->where('is_active', true)->get(['id', 'name', 'type']);

        $closings = MonthlyClosing::where('client_id', $clientId)
            ->where('month', $month)
            ->selectRaw('warehouse_id, SUM(opening_value) as opening, SUM(purchases_value) as purchases, SUM(diff_value) as diff')
            ->groupBy('warehouse_id')
            ->get()
            ->keyBy('warehouse_id');

        $result = [];
        foreach ($warehouses as $wh) {
            $c = $closings->get($wh->id);
            $result[] = [
                'name' => $wh->name,
                'type' => $wh->type,
                'opening' => (float) ($c->opening ?? 0),
                'purchases' => (float) ($c->purchases ?? 0),
                'diff' => (float) ($c->diff ?? 0),
            ];
        }

        return response()->json($result);
    }
}
