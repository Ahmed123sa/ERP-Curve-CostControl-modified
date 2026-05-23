<?php
namespace App\Services\MenuEngineering;

use App\Models\ActivityLog;
use App\Models\Item;
use App\Models\MenuEngineering\MenuEngineeringMenu;
use App\Models\MenuEngineering\MenuRecipe;
use App\Models\MenuEngineering\MenuRecipeItem;
use App\Models\StockLedger;
use App\Models\Warehouse;
use App\Models\User;
use App\Services\CostCalculationService;
use Illuminate\Support\Facades\DB;

class SmartAnalyticsService
{
    public function __construct(
        private CostCalculationService $calc,
        private RecipeCostCalculationService $recipeCalc,
    ) {}

    // ── 1. Inventory Alerts ──
    public function inventoryAlerts(string $clientId, float $warningThresholdPct = 20): array
    {
        $items = Item::where('client_id', $clientId)
            ->where('is_active', true)
            ->whereNotNull('min_stock_level')
            ->get(['id', 'name', 'unit', 'min_stock_level', 'max_stock_level', 'reorder_qty']);

        $warehouses = Warehouse::where('client_id', $clientId)
            ->where('is_active', true)
            ->get(['id', 'name', 'type']);

        $critical = [];
        $warning = [];

        foreach ($items as $item) {
            foreach ($warehouses as $wh) {
                $qty = $this->calc->currentStock($clientId, $wh->id, $item->id);
                $min = (float) $item->min_stock_level;

                if ($qty <= $min) {
                    $critical[] = [
                        'item_id' => $item->id,
                        'item_name' => $item->name,
                        'unit' => $item->unit,
                        'warehouse_id' => $wh->id,
                        'warehouse_name' => $wh->name,
                        'current_qty' => $qty,
                        'min_stock_level' => $min,
                        'max_stock_level' => (float) ($item->max_stock_level ?? 0),
                        'reorder_qty' => (float) ($item->reorder_qty ?? 0),
                        'deficit' => round($min - $qty, 3),
                    ];
                } elseif ($min > 0 && $qty <= $min * (1 + $warningThresholdPct / 100)) {
                    $warning[] = [
                        'item_id' => $item->id,
                        'item_name' => $item->name,
                        'unit' => $item->unit,
                        'warehouse_id' => $wh->id,
                        'warehouse_name' => $wh->name,
                        'current_qty' => $qty,
                        'min_stock_level' => $min,
                        'max_stock_level' => (float) ($item->max_stock_level ?? 0),
                        'reorder_qty' => (float) ($item->reorder_qty ?? 0),
                    ];
                }
            }
        }

        return [
            'critical' => $critical,
            'warning' => $warning,
            'summary' => [
                'critical_count' => count($critical),
                'warning_count' => count($warning),
                'ok_count' => $items->count() * $warehouses->count() - count($critical) - count($warning),
            ],
        ];
    }

    // ── 2. Top Purchased Items ──
    public function topPurchases(string $clientId, ?string $from = null, ?string $to = null, int $limit = 10): array
    {
        $query = StockLedger::where('client_id', $clientId)
            ->where('voucher_type', 'purchase')
            ->where('movement_type', 'in');

        if ($from) $query->where('date', '>=', $from);
        if ($to) $query->where('date', '<=', $to);

        $items = $query->select(
                'item_id',
                DB::raw('COUNT(*) as purchase_count'),
                DB::raw('SUM(qty) as total_qty'),
                DB::raw('SUM(total_cost) as total_value')
            )
            ->groupBy('item_id')
            ->orderByDesc('total_value')
            ->limit($limit)
            ->get();

        $itemIds = $items->pluck('item_id');
        $itemNames = Item::whereIn('id', $itemIds)->pluck('name', 'id');
        $itemUnits = Item::whereIn('id', $itemIds)->pluck('unit', 'id');

        $result = [];
        foreach ($items as $i => $row) {
            $result[] = [
                'rank' => $i + 1,
                'item_id' => $row->item_id,
                'item_name' => $itemNames[$row->item_id] ?? '—',
                'unit' => $itemUnits[$row->item_id] ?? '—',
                'purchase_count' => (int) $row->purchase_count,
                'total_qty' => (float) $row->total_qty,
                'total_value' => (float) $row->total_value,
            ];
        }

        return [
            'period' => ['from' => $from ?? 'all', 'to' => $to ?? 'all'],
            'items' => $result,
            'total_value_all' => array_sum(array_column($result, 'total_value')),
        ];
    }

    // ── 3. Price Change Detection ──
    public function priceChanges(string $clientId, float $thresholdPct = 10, ?string $from = null, ?string $to = null, int $limit = 50): array
    {
        $query = ActivityLog::where('client_id', $clientId)
            ->where('action', 'price_updated');

        if ($from) $query->where('created_at', '>=', $from);
        if ($to) $query->where('created_at', '<=', $to);

        $logs = $query->orderByDesc('created_at')->limit($limit)->get();

        $itemIds = $logs->pluck('entity_id')->unique();
        $itemNames = Item::whereIn('id', $itemIds)->pluck('name', 'id');
        $userIds = $logs->pluck('user_id')->unique();
        $userNames = User::whereIn('id', $userIds)->pluck('name', 'id');

        $changes = [];
        foreach ($logs as $log) {
            $old = (float) ($log->old_values['default_cost'] ?? 0);
            $new = (float) ($log->new_values['default_cost'] ?? 0);
            $delta = $new - $old;
            $deltaPct = $old > 0 ? round(($delta / $old) * 100, 1) : 0;
            $isUnusual = abs($deltaPct) >= $thresholdPct;

            $changes[] = [
                'id' => $log->id,
                'item_id' => $log->entity_id,
                'item_name' => $itemNames[$log->entity_id] ?? '—',
                'old_cost' => $old,
                'new_cost' => $new,
                'delta' => round($delta, 2),
                'delta_pct' => $deltaPct,
                'direction' => $delta > 0 ? 'up' : ($delta < 0 ? 'down' : 'same'),
                'is_unusual' => $isUnusual,
                'changed_by' => $userNames[$log->user_id] ?? '—',
                'date' => $log->created_at->toDateTimeString(),
            ];
        }

        return [
            'threshold' => $thresholdPct,
            'changes' => $changes,
            'unusual_count' => count(array_filter($changes, fn($c) => $c['is_unusual'])),
        ];
    }

    // ── 4. Cost Impact Analysis ──
    public function costImpact(string $clientId, ?string $from = null, ?string $to = null, int $limit = 50): array
    {
        $priceChanges = $this->priceChanges($clientId, 0, $from, $to, $limit);

        $impacts = [];
        $totalDelta = 0;

        foreach ($priceChanges['changes'] as $change) {
            $itemId = $change['item_id'];
            $newCost = $change['new_cost'];

            $recipes = MenuRecipeItem::where('ingredient_id', $itemId)
                ->whereHas('recipe', fn($q) => $q->where('client_id', $clientId)->whereNull('deleted_at'))
                ->get();

            if ($recipes->isEmpty()) continue;

            $recipesGrouped = [];
            $recipesDelta = 0;

            foreach ($recipes as $ri) {
                $oldLineTotal = (float) $ri->line_total;
                $data = $ri->toArray();
                $data['purchase_unit_price'] = $newCost;
                $recalc = $this->recipeCalc->calculateItemFromArray($data);
                $newLineTotal = $recalc['line_total'];
                $delta = round($newLineTotal - $oldLineTotal, 4);

                $recipesGrouped[] = [
                    'recipe_id' => $ri->recipe_id,
                    'recipe_name' => $ri->recipe->name ?? '—',
                    'old_line_total' => $oldLineTotal,
                    'new_line_total' => $newLineTotal,
                    'delta' => $delta,
                    'delta_pct' => $oldLineTotal > 0 ? round(($delta / $oldLineTotal) * 100, 1) : 0,
                ];
                $recipesDelta += $delta;
            }

            $impacts[] = [
                'ingredient_id' => $itemId,
                'ingredient_name' => $change['item_name'],
                'old_cost' => $change['old_cost'],
                'new_cost' => $change['new_cost'],
                'delta_pct' => $change['delta_pct'],
                'direction' => $change['direction'],
                'is_unusual' => $change['is_unusual'],
                'recipes_affected' => count($recipesGrouped),
                'recipes' => $recipesGrouped,
            ];
            $totalDelta += $recipesDelta;
        }

        return [
            'impacts' => $impacts,
            'total_delta' => round($totalDelta, 2),
        ];
    }

    // ── 5. Cost Contribution (Pareto) ──
    public function costContribution(string $clientId, ?string $menuId = null, ?string $branchId = null): array
    {
        $query = MenuRecipe::where('client_id', $clientId)
            ->where('status', 'active');

        if ($menuId) $query->where('menu_id', $menuId);
        if ($branchId) $query->where('branch_id', $branchId);

        $recipes = $query->get();
        $result = [];

        foreach ($recipes as $recipe) {
            $items = $recipe->items()->with('ingredient:id,name')->get();
            $totalCost = (float) $items->sum('line_total');
            $sellingPrice = (float) ($recipe->selling_price ?? 0);

            $ingredients = [];
            foreach ($items as $item) {
                $lineTotal = (float) $item->line_total;
                $pct = $totalCost > 0 ? round(($lineTotal / $totalCost) * 100, 1) : 0;
                $ingredients[] = [
                    'ingredient_id' => $item->ingredient_id,
                    'ingredient_name' => $item->ingredient->name ?? '—',
                    'line_total' => $lineTotal,
                    'pct' => $pct,
                ];
            }

            usort($ingredients, fn($a, $b) => $b['pct'] <=> $a['pct']);

            $cumulative = 0;
            foreach ($ingredients as &$ing) {
                $cumulative += $ing['pct'];
                $ing['cumulative_pct'] = round($cumulative, 1);
                $ing['pareto_group'] = $cumulative <= 80 ? 'A' : ($cumulative <= 95 ? 'B' : 'C');
            }

            $result[] = [
                'recipe_id' => $recipe->id,
                'recipe_name' => $recipe->name,
                'total_cost' => $totalCost,
                'selling_price' => $sellingPrice,
                'food_cost_pct' => $sellingPrice > 0 ? round(($totalCost / $sellingPrice) * 100, 2) : 0,
                'ingredients' => $ingredients,
            ];
        }

        return ['recipes' => $result];
    }

    // ── 6. Total Stock Value ──
    public function stockValueSummary(string $clientId): array
    {
        $warehouses = Warehouse::where('client_id', $clientId)
            ->where('is_active', true)
            ->get(['id', 'name', 'type']);

        $items = Item::where('client_id', $clientId)
            ->where('is_active', true)
            ->pluck('id');

        $warehouseData = [];
        $totalValue = 0;

        foreach ($warehouses as $wh) {
            $whValue = 0;
            $whCount = 0;

            foreach ($items as $itemId) {
                $val = $this->calc->currentStockValue($clientId, $wh->id, $itemId);
                if ($val > 0) {
                    $whValue += $val;
                    $whCount++;
                }
            }

            $warehouseData[] = [
                'warehouse_id' => $wh->id,
                'name' => $wh->name,
                'type' => $wh->type,
                'items_count' => $whCount,
                'total_value' => round($whValue, 2),
            ];
            $totalValue += $whValue;
        }

        foreach ($warehouseData as &$wd) {
            $wd['pct'] = $totalValue > 0 ? round(($wd['total_value'] / $totalValue) * 100, 1) : 0;
        }

        usort($warehouseData, fn($a, $b) => $b['total_value'] <=> $a['total_value']);

        return [
            'total_value' => round($totalValue, 2),
            'warehouses' => $warehouseData,
        ];
    }

    // ── Smart Summary (for Dashboard) ──
    public function smartSummary(string $clientId): array
    {
        $alerts = $this->inventoryAlerts($clientId);
        $stockValue = $this->stockValueSummary($clientId);

        $priceLogs = ActivityLog::where('client_id', $clientId)
            ->where('action', 'price_updated')
            ->orderByDesc('created_at')
            ->limit(10)
            ->get();

        $itemIds = $priceLogs->pluck('entity_id')->unique();
        $itemNames = Item::whereIn('id', $itemIds)->pluck('name', 'id');

        $recentPriceChanges = [];
        foreach ($priceLogs as $log) {
            $old = (float) ($log->old_values['default_cost'] ?? 0);
            $new = (float) ($log->new_values['default_cost'] ?? 0);
            $deltaPct = $old > 0 ? round((($new - $old) / $old) * 100, 1) : 0;
            $recentPriceChanges[] = [
                'item_name' => $itemNames[$log->entity_id] ?? '—',
                'old_cost' => $old,
                'new_cost' => $new,
                'delta_pct' => $deltaPct,
                'date' => $log->created_at->toDateTimeString(),
            ];
        }

        $purchaseCount = (int) StockLedger::where('client_id', $clientId)
            ->where('voucher_type', 'purchase')
            ->where('movement_type', 'in')
            ->whereMonth('created_at', now()->month)
            ->count('id');

        return [
            'critical_alerts' => array_slice($alerts['critical'], 0, 5),
            'critical_count' => $alerts['summary']['critical_count'],
            'warning_count' => $alerts['summary']['warning_count'],
            'stock_value' => $stockValue['total_value'],
            'recent_price_changes' => array_slice($recentPriceChanges, 0, 5),
            'monthly_purchase_count' => $purchaseCount,
        ];
    }
}
