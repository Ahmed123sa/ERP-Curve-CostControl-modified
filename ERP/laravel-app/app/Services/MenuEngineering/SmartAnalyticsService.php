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
    public function inventoryAlerts(string $clientId, float $warningThresholdPct = 50, ?array $warehouseIds = null): array
    {
        $items = Item::where('client_id', $clientId)
            ->where('is_active', true)
            ->whereNotNull('min_stock_level')
            ->get(['id', 'name', 'unit', 'min_stock_level', 'default_warehouse_id']);

        $whQuery = Warehouse::where('client_id', $clientId)
            ->where('is_active', true)
            ->where('type', '!=', 'branch');
        if ($warehouseIds) {
            $whQuery->whereIn('id', $warehouseIds);
        }
        $allWarehouses = $whQuery->get(['id', 'name', 'type']);
        $warehousesById = $allWarehouses->keyBy('id');

        $whIds = $allWarehouses->pluck('id')->toArray();

        // Pre-fetch avg daily consumption (last 30 days) for all items × warehouses
        $thirtyDaysAgo = now()->subDays(30)->toDateString();
        $consumptionQuery = StockLedger::select('item_id', 'warehouse_id', DB::raw('SUM(qty) as total_out'))
            ->where('client_id', $clientId)
            ->whereIn('warehouse_id', $whIds)
            ->whereIn('movement_type', ['out', 'transfer_out'])
            ->where('date', '>=', $thirtyDaysAgo)
            ->groupBy('item_id', 'warehouse_id')
            ->get();

        $consumptionMap = [];
        foreach ($consumptionQuery as $row) {
            $consumptionMap[$row->item_id . '_' . $row->warehouse_id] = (float) $row->total_out;
        }

        $outOfStock = [];
        $critical = [];
        $warning = [];
        $ok = 0;

        foreach ($items as $item) {
            $targetWh = $item->default_warehouse_id && $warehousesById->has($item->default_warehouse_id)
                ? [$warehousesById[$item->default_warehouse_id]]
                : $allWarehouses;

            foreach ($targetWh as $wh) {
                $qty = $this->calc->currentStock($clientId, $wh->id, $item->id);
                $min = (float) $item->min_stock_level;
                $totalOut = $consumptionMap[$item->id . '_' . $wh->id] ?? 0;
                $avgDaily = $totalOut / 30;
                $daysUntilStockout = $avgDaily > 0 ? round($qty / $avgDaily, 1) : null;
                $usagePct = $min > 0 ? round(($qty / $min) * 100, 1) : ($qty > 0 ? 999 : 0);

                $entry = [
                    'item_id' => $item->id,
                    'item_name' => $item->name,
                    'unit' => $item->unit,
                    'warehouse_id' => $wh->id,
                    'warehouse_name' => $wh->name,
                    'current_qty' => $qty,
                    'min_stock_level' => $min,
                    'avg_daily_consumption' => round($avgDaily, 3),
                    'days_until_stockout' => $daysUntilStockout,
                    'usage_pct' => $usagePct,
                ];

                if ($qty <= 0) {
                    $outOfStock[] = $entry;
                } elseif ($qty < $min) {
                    $critical[] = $entry;
                } elseif ($min > 0 && $qty <= $min * (1 + $warningThresholdPct / 100)) {
                    $warning[] = $entry;
                } else {
                    $ok++;
                }
            }
        }

        // Sort by usage_pct ascending (most urgent first)
        $sortByUsage = fn($a, $b) => $a['usage_pct'] <=> $b['usage_pct'];
        usort($outOfStock, $sortByUsage);
        usort($critical, $sortByUsage);
        usort($warning, $sortByUsage);

        return [
            'out_of_stock' => $outOfStock,
            'critical' => $critical,
            'warning' => $warning,
            'summary' => [
                'out_of_stock_count' => count($outOfStock),
                'critical_count' => count($critical),
                'warning_count' => count($warning),
                'ok_count' => $ok,
            ],
        ];
    }

    // ── 2. Top Purchased Items ──
    public function topPurchases(string $clientId, ?string $from = null, ?string $to = null, int $limit = 10, ?string $warehouseId = null): array
    {
        $query = StockLedger::where('client_id', $clientId)
            ->where('voucher_type', 'purchase')
            ->where('movement_type', 'in');

        if ($from) $query->where('date', '>=', $from);
        if ($to) $query->where('date', '<=', $to);
        if ($warehouseId) $query->where('warehouse_id', $warehouseId);

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
        $itemLookup = Item::whereIn('id', $itemIds)->get()->keyBy('id');

        $result = [];
        foreach ($items as $i => $row) {
            $item = $itemLookup[$row->item_id] ?? null;
            $result[] = [
                'rank' => $i + 1,
                'item_id' => $row->item_id,
                'item_name' => $item->name ?? '—',
                'unit' => $item->unit ?? '—',
                'category' => $item->category ?? null,
                'purchase_count' => (int) $row->purchase_count,
                'total_qty' => (float) $row->total_qty,
                'total_value' => (float) $row->total_value,
                'avg_unit_price' => $row->total_qty > 0 ? round($row->total_value / $row->total_qty, 2) : 0,
            ];
        }

        $totalAll = array_sum(array_column($result, 'total_value'));
        foreach ($result as &$r) {
            $r['contribution_pct'] = $totalAll > 0 ? round(($r['total_value'] / $totalAll) * 100, 1) : 0;
        }

        return [
            'period' => ['from' => $from ?? 'all', 'to' => $to ?? 'all'],
            'items' => $result,
            'total_value_all' => $totalAll,
        ];
    }

    // ── 3. Price Change Detection (daily settled cost from actual stock movements) ──
    public function priceChanges(string $clientId, float $thresholdPct = 10, ?string $from = null, ?string $to = null, int $limit = 50): array
    {
        $mainWhIds = Warehouse::where('client_id', $clientId)
            ->where('is_active', true)
            ->where('type', '!=', 'branch')
            ->pluck('id');

        $query = StockLedger::where('client_id', $clientId)
            ->whereIn('warehouse_id', $mainWhIds)
            ->where('movement_type', 'in')
            ->where('voucher_type', 'purchase')
            ->where('unit_cost', '>', 0);

        if ($from) $query->where('date', '>=', $from);
        if ($to) $query->where('date', '<=', $to);

        $rows = $query->select('item_id', 'warehouse_id', 'unit_cost', 'date', 'created_at')
            ->orderBy('item_id')
            ->orderBy('warehouse_id')
            ->orderBy('date')
            ->orderBy('created_at')
            ->get();

        // Daily settled cost: for each (item_id, warehouse_id, date), take last entry
        $daily = collect();
        $grouped = $rows->groupBy(fn($r) => $r->item_id . '_' . $r->warehouse_id . '_' . $r->date->format('Y-m-d'));
        foreach ($grouped as $entries) {
            $daily->push($entries->last());
        }

        $byItemWh = $daily->groupBy(fn($r) => $r->item_id . '_' . $r->warehouse_id);

        // ── Also read activity_logs for price_updated (manufactured items, no purchase records) ──
        $priceLogs = ActivityLog::where('client_id', $clientId)
            ->where('action', 'price_updated')
            ->orderBy('created_at')
            ->get();

        $stockItemIds = $rows->pluck('item_id')->unique();
        $alItemIds = $priceLogs->pluck('entity_id')->unique();
        $allItemIds = $stockItemIds->merge($alItemIds)->unique();
        $itemNames = Item::whereIn('id', $allItemIds)->pluck('name', 'id');
        $whNames = Warehouse::whereIn('id', $mainWhIds)->pluck('name', 'id');

        $changes = [];

        // ── Process stock_ledger (purchase-based) changes ──
        foreach ($byItemWh as $key => $dayEntries) {
            $sorted = $dayEntries->sortBy('date')->values();
            for ($i = 1; $i < count($sorted); $i++) {
                $prev = $sorted[$i - 1];
                $curr = $sorted[$i];
                $oldCost = (float) $prev->unit_cost;
                $newCost = (float) $curr->unit_cost;
                if ($oldCost == $newCost) continue;
                $delta = $newCost - $oldCost;
                $deltaPct = $oldCost > 0 ? round(($delta / $oldCost) * 100, 1) : 0;
                if (abs($deltaPct) < $thresholdPct) continue;

                $currentQty = $this->calc->currentStock($clientId, $curr->warehouse_id, $curr->item_id);
                $totalImpact = round($delta * $currentQty, 2);
                $avgCost = $this->calc->weightedAverageCost($clientId, $curr->warehouse_id, $curr->item_id);

                $changes[] = [
                    'item_id' => $curr->item_id,
                    'item_name' => $itemNames[$curr->item_id] ?? '—',
                    'old_cost' => $oldCost,
                    'new_cost' => $newCost,
                    'avg_cost' => $avgCost,
                    'delta' => round($delta, 2),
                    'delta_pct' => $deltaPct,
                    'direction' => $delta > 0 ? 'up' : 'down',
                    'total_impact' => $totalImpact,
                    'warehouse_id' => $curr->warehouse_id,
                    'source' => $whNames[$curr->warehouse_id] ?? '—',
                    'date' => $curr->date instanceof \Carbon\Carbon ? $curr->date->toDateString() : $curr->date,
                    'source_type' => 'purchase',
                ];
            }
        }

        // ── Process activity_logs (recipe sync) changes ──
        $alByItem = $priceLogs->groupBy('entity_id');
        foreach ($alByItem as $itemId => $logs) {
            $sorted = $logs->sortBy('created_at')->values();
            $entryCount = count($sorted);
            if ($entryCount === 0) continue;

            for ($i = 0; $i < $entryCount; $i++) {
                // سجل واحد: old_values → new_values هو نفسه التغيير
                if ($entryCount === 1) {
                    $oldCost = (float) ($sorted[0]->old_values['default_cost'] ?? 0);
                    $newCost = (float) ($sorted[0]->new_values['default_cost'] ?? 0);
                    $logDate = $sorted[0]->created_at;
                } else {
                    // سجلين فأكثر: نقارن أول→ثاني، ثاني→ثالث، ...
                    if ($i === 0) continue;
                    $prev = $sorted[$i - 1];
                    $curr = $sorted[$i];
                    $oldCost = (float) ($prev->old_values['default_cost'] ?? 0);
                    $newCost = (float) ($curr->new_values['default_cost'] ?? 0);
                    $logDate = $curr->created_at;
                }

                if ($oldCost <= 0 || $newCost <= 0) continue;
                if ($oldCost == $newCost) continue;
                $delta = $newCost - $oldCost;
                $deltaPct = $oldCost > 0 ? round(($delta / $oldCost) * 100, 1) : 0;
                if (abs($deltaPct) < $thresholdPct) continue;

                $totalQty = 0;
                foreach ($mainWhIds as $whId) {
                    $totalQty += $this->calc->currentStock($clientId, $whId, $itemId);
                }
                $totalImpact = round($delta * $totalQty, 2);

                $changes[] = [
                    'item_id' => $itemId,
                    'item_name' => $itemNames[$itemId] ?? '—',
                    'old_cost' => $oldCost,
                    'new_cost' => $newCost,
                    'avg_cost' => null,
                    'delta' => round($delta, 2),
                    'delta_pct' => $deltaPct,
                    'direction' => $delta > 0 ? 'up' : 'down',
                    'total_impact' => $totalImpact,
                    'warehouse_id' => null,
                    'source' => 'تحديث تكلفة صنف',
                    'date' => $logDate instanceof \Carbon\Carbon ? $logDate->toDateString() : $logDate,
                    'source_type' => 'activity_log',
                ];
            }
        }

        usort($changes, fn($a, $b) => strcmp($b['date'], $a['date']));
        $changes = array_slice($changes, 0, $limit);
        $netImpact = array_sum(array_column($changes, 'total_impact'));

        return [
            'threshold' => $thresholdPct,
            'changes' => $changes,
            'count' => count($changes),
            'net_impact' => round($netImpact, 2),
        ];
    }

    // ── 4. Cost Impact Analysis (كروت المنيوهات + الفرق بين قديم/حالي) ──
    public function costImpact(string $clientId, ?string $from = null, ?string $to = null): array
    {
        $menus = MenuEngineeringMenu::where('client_id', $clientId)
            ->with(['recipes' => function($q) {
                $q->where('status', 'active')->where('exclude_from_menu', false)->with('items.ingredient:id,name');
            }])
            ->get();

        if ($menus->isEmpty()) {
            return [
                'period' => ['from' => $from ?? 'all', 'to' => $to ?? 'all'],
                'menus' => [],
            ];
        }

        $ingredientIds = collect();
        foreach ($menus as $menu) {
            foreach ($menu->recipes as $recipe) {
                foreach ($recipe->items as $item) {
                    $ingredientIds->push($item->ingredient_id);
                }
            }
        }
        $ingredientIds = $ingredientIds->unique()->values();

        $allMovements = StockLedger::whereIn('item_id', $ingredientIds)
            ->where('unit_cost', '>', 0)
            ->where(function($q) {
                $q->where(function($q2) {
                    $q2->where('movement_type', 'in')
                       ->where('voucher_type', 'purchase');
                })->orWhere('voucher_type', 'opening');
            })
            ->when($from, fn($q) => $q->where('date', '>=', $from))
            ->when($to, fn($q) => $q->where('date', '<=', $to))
            ->orderBy('item_id')
            ->orderBy('warehouse_id')
            ->orderBy('date')
            ->orderBy('created_at')
            ->get(['item_id', 'warehouse_id', 'unit_cost', 'date', 'created_at']);

        // Daily settled cost: لكل (صنف, مخزن, تاريخ) نأخذ آخر إدخال
        $daily = collect();
        $grouped = $allMovements->groupBy(fn($r) => $r->item_id . '_' . $r->warehouse_id . '_' . $r->date->format('Y-m-d'));
        foreach ($grouped as $entries) {
            $daily->push($entries->last());
        }

        $byItem = $daily->groupBy('item_id');
        $itemDefaults = Item::whereIn('id', $ingredientIds)->pluck('default_cost', 'id');

        // Read activity_logs for price_updated (ingredients with no purchase records)
        $priceUpdateLogs = ActivityLog::where('client_id', $clientId)
            ->where('action', 'price_updated')
            ->whereIn('entity_id', $ingredientIds)
            ->orderBy('created_at')
            ->get()
            ->groupBy('entity_id');

        $prices = [];
        foreach ($ingredientIds as $id) {
            $entries = $byItem->get($id, collect())->sortBy('date')->values();
            $def = (float) ($itemDefaults[$id] ?? 0);
            if ($entries->isNotEmpty()) {
                $prices[$id] = [
                    'old' => (float) $entries->first()->unit_cost,
                    'current' => (float) $entries->last()->unit_cost,
                ];
            } elseif (isset($priceUpdateLogs[$id])) {
                $logs = $priceUpdateLogs[$id]->sortBy('created_at')->values();
                $oldCost = (float) ($logs->first()->old_values['default_cost'] ?? 0);
                $currentCost = (float) ($logs->last()->new_values['default_cost'] ?? 0);
                $prices[$id] = [
                    'old' => $oldCost ?: $def,
                    'current' => $currentCost ?: $def,
                ];
            } else {
                $prices[$id] = [
                    'old' => $def,
                    'current' => $def,
                ];
            }
        }

        $result = [];
        foreach ($menus as $menu) {
            $menuOldTotal = 0;
            $menuCurrentTotal = 0;
            $affectedRecipes = [];

            foreach ($menu->recipes as $recipe) {
                $recipeOldTotal = 0;
                $recipeCurrentTotal = 0;
                $recipeSellingPrice = (float) ($recipe->selling_price ?? 0);
                $recipeIngredients = [];

                foreach ($recipe->items as $item) {
                    $id = $item->ingredient_id;
                    $qty = (float) $item->qty;
                    $oldPrice = $prices[$id]['old'] ?? 0;
                    $currentPrice = $prices[$id]['current'] ?? 0;

                    // قديم = إعادة حساب بـ أول سعر مستقر
                    $ro = $item->toArray();
                    $ro['purchase_unit_price'] = $oldPrice;
                    $itemOldTotal = round((float) ($this->recipeCalc->calculateItemFromArray($ro)['line_total'] ?? 0), 4);

                    // جديد = إعادة حساب بـ آخر سعر مستقر
                    $rc = $item->toArray();
                    $rc['purchase_unit_price'] = $currentPrice;
                    $itemCurrentTotal = round((float) ($this->recipeCalc->calculateItemFromArray($rc)['line_total'] ?? 0), 4);

                    $recipeOldTotal += $itemOldTotal;
                    $recipeCurrentTotal += $itemCurrentTotal;

                    $ingDelta = round($itemCurrentTotal - $itemOldTotal, 2);
                    if (abs($ingDelta) < 0.01) continue;

                    $recipeIngredients[] = [
                        'ingredient_name' => $item->ingredient?->name ?? '—',
                        'old_unit_cost' => $oldPrice,
                        'current_unit_cost' => $currentPrice,
                        'total_old' => round($itemOldTotal, 2),
                        'total_current' => round($itemCurrentTotal, 2),
                        'delta' => $ingDelta,
                    ];
                }

                $recipeOldFc = $recipeSellingPrice > 0 ? round(($recipeOldTotal / $recipeSellingPrice) * 100, 1) : 0;
                $recipeCurrentFc = $recipeSellingPrice > 0 ? round(($recipeCurrentTotal / $recipeSellingPrice) * 100, 1) : 0;
                $recipeImpact = round($recipeCurrentTotal - $recipeOldTotal, 2);

                if (count($recipeIngredients) > 0) {
                    $affectedRecipes[] = [
                        'recipe_name' => $recipe->name,
                        'selling_price' => $recipeSellingPrice,
                        'old_fc_pct' => $recipeOldFc,
                        'current_fc_pct' => $recipeCurrentFc,
                        'fc_delta' => round($recipeCurrentFc - $recipeOldFc, 1),
                        'total_impact' => $recipeImpact,
                        'ingredients' => $recipeIngredients,
                    ];
                }

                $menuOldTotal += $recipeOldTotal;
                $menuCurrentTotal += $recipeCurrentTotal;
            }

            $menuDelta = round($menuCurrentTotal - $menuOldTotal, 2);
            $menuSellingPrice = (float) $menu->recipes->sum('selling_price');
            $menuOldFc = $menuSellingPrice > 0 ? round(($menuOldTotal / $menuSellingPrice) * 100, 1) : 0;
            $menuCurrentFc = $menuSellingPrice > 0 ? round(($menuCurrentTotal / $menuSellingPrice) * 100, 1) : 0;
            $result[] = [
                'menu_id' => $menu->id,
                'menu_name' => $menu->name,
                'recipes_count' => $menu->recipes->count(),
                'total_selling_price' => $menuSellingPrice,
                'old_total_cost' => round($menuOldTotal, 2),
                'current_total_cost' => round($menuCurrentTotal, 2),
                'delta' => $menuDelta,
                'delta_pct' => $menuOldTotal > 0 ? round(($menuDelta / $menuOldTotal) * 100, 1) : 0,
                'old_fc_pct' => $menuOldFc,
                'current_fc_pct' => $menuCurrentFc,
                'fc_delta' => round($menuCurrentFc - $menuOldFc, 1),
                'affected_recipes' => $affectedRecipes,
            ];
        }

        usort($result, fn($a, $b) => abs($b['delta']) <=> abs($a['delta']));

        return [
            'period' => ['from' => $from ?? 'all', 'to' => $to ?? 'all'],
            'menus' => $result,
        ];
    }

    // ── 5. Cost Contribution (Pareto) ──
    public function costContribution(string $clientId, ?string $menuId = null, ?string $branchId = null): array
    {
        $query = MenuRecipe::where('client_id', $clientId)
            ->where('status', 'active')
            ->where('exclude_from_menu', false);

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
            ->whereMonth('date', now()->month)
            ->whereYear('date', now()->year)
            ->distinct('ref_id')
            ->count('ref_id');

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
