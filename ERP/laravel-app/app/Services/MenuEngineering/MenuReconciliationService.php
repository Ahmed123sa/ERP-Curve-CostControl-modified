<?php
namespace App\Services\MenuEngineering;

use App\Models\Item;
use App\Models\MenuEngineering\MenuRecipe;
use App\Models\MenuEngineering\MenuSale;
use App\Models\MonthlyClosing;
use App\Models\StockLedger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MenuReconciliationService
{
    public function detailedReconcile(string $clientId, string $warehouseId, string $from, string $to, array $inlineSales = []): array
    {
        // 1. Get active recipes for this branch (or global), excluding box items
        $recipes = MenuRecipe::where('client_id', $clientId)
            ->where(function ($q) use ($warehouseId) {
                $q->where('branch_id', $warehouseId)->orWhereNull('branch_id');
            })
            ->where('status', 'active')
            ->where('exclude_from_reconciliation', false)
            ->with('items')
            ->get();

        // 2. Get sales data: prefer inlineSales, fallback to DB
        $salesData = [];
        if (!empty($inlineSales)) {
            $salesData = $inlineSales; // recipe_id => qty_sold (already from user input)
        } else {
            $salesData = MenuSale::where('client_id', $clientId)
                ->where('branch_id', $warehouseId)
                ->whereBetween('sale_date', [$from, $to])
                ->selectRaw('recipe_id, SUM(qty_sold) as total_sold')
                ->groupBy('recipe_id')
                ->pluck('total_sold', 'recipe_id')
                ->toArray();
        }

        // 3. Collect all unique ingredient IDs from ALL recipes
        $allIngredientIds = [];
        $recipeMap = []; // recipe_id → recipe data

        foreach ($recipes as $r) {
            $catName = $r->category ?? 'أخرى';
            $ingQtys = [];
            foreach ($r->items as $item) {
                $ingId = $item->ingredient_id;
                $ingQtys[$ingId] = (float) $item->qty;
                $allIngredientIds[$ingId] = $ingId;
            }
            $recipeMap[$catName][] = [
                'id'           => $r->id,
                'name'         => $r->name,
                'recipe_qty'   => $ingQtys,
                'qty_sold'     => (float) ($salesData[$r->id] ?? 0),
            ];
        }

        // 4. Calculate theoretical totals per ingredient
        $totalTheoretical = [];
        foreach ($recipeMap as $catName => $items) {
            foreach ($items as $item) {
                $sold = $item['qty_sold'];
                foreach ($item['recipe_qty'] as $ingId => $qty) {
                    $totalTheoretical[$ingId] = ($totalTheoretical[$ingId] ?? 0) + ($qty * $sold);
                }
            }
        }
        array_walk($totalTheoretical, fn(&$v) => $v = round($v, 4));

        // 5. Get ingredient names
        $ingredientNames = [];
        if (!empty($allIngredientIds)) {
            $items = Item::whereIn('id', $allIngredientIds)->get(['id', 'name']);
            foreach ($items as $item) {
                $ingredientNames[$item->id] = $item->name;
            }
        }

        // 6. Get inventory data per ingredient
        $opening = [];
        $purchases = [];
        $closing = [];
        foreach ($allIngredientIds as $ingId) {
            $inv = $this->inventoryForItem($clientId, $warehouseId, $ingId, $from, $to);
            $opening[$ingId] = $inv['opening'];
            $purchases[$ingId] = $inv['purchases'];
            $closing[$ingId] = $inv['closing'];
        }

        $actual = [];
        $variance = [];
        foreach ($allIngredientIds as $ingId) {
            $actual[$ingId] = round($opening[$ingId] + $purchases[$ingId] - $closing[$ingId], 4);
            $theoretical = $totalTheoretical[$ingId] ?? 0;
            $variance[$ingId] = round($theoretical - $actual[$ingId], 4);
        }

        Log::info('MenuReconciliation::detailed', [
            'client_id' => $clientId, 'warehouse_id' => $warehouseId,
            'from' => $from, 'to' => $to,
            'recipes_count' => $recipes->count(),
            'ingredients_count' => count($allIngredientIds),
        ]);

        return [
            'ingredient_ids'   => array_values($allIngredientIds),
            'ingredient_names' => $ingredientNames,
            'categories'       => $recipeMap,
            'totals'           => $totalTheoretical,
            'opening'          => $opening,
            'purchases'        => $purchases,
            'closing'          => $closing,
            'actual'           => $actual,
            'variance'         => $variance,
        ];
    }

    private function inventoryForItem(string $clientId, string $warehouseId, string $itemId, string $from, string $to): array
    {
        // Try official MonthlyClosing first (same source as Grand Summary / Financial Details reports)
        $month = substr($from, 0, 7); // e.g. '2026-05-01' -> '2026-05'
        $mc = MonthlyClosing::where('warehouse_id', $warehouseId)
            ->where('item_id', $itemId)
            ->where('month', $month)
            ->first();

        if ($mc) {
            // Actual physical count if available, otherwise assume 0 (no verified stock)
            $closing = $mc->closing_qty_actual !== null
                ? (float) $mc->closing_qty_actual
                : 0.0;

            return [
                'opening'   => round((float) $mc->opening_qty, 4),
                'purchases' => round((float) $mc->in_qty, 4),
                'closing'   => round($closing, 4),
            ];
        }

        // Fallback: query stock_ledger directly
        $opening = (float) StockLedger::where('client_id', $clientId)
            ->where('warehouse_id', $warehouseId)
            ->where('item_id', $itemId)
            ->where('date', '<', $from)
            ->sum(DB::raw("CASE WHEN movement_type IN ('in','transfer_in') THEN qty ELSE -qty END"));

        $incoming = (float) StockLedger::where('client_id', $clientId)
            ->where('warehouse_id', $warehouseId)
            ->where('item_id', $itemId)
            ->whereBetween('date', [$from, $to])
            ->whereIn('movement_type', ['in', 'transfer_in'])
            ->sum('qty');

        $closing = (float) StockLedger::where('client_id', $clientId)
            ->where('warehouse_id', $warehouseId)
            ->where('item_id', $itemId)
            ->where('date', '<=', $to)
            ->sum(DB::raw("CASE WHEN movement_type IN ('in','transfer_in') THEN qty ELSE -qty END"));

        return [
            'opening'   => round($opening, 4),
            'purchases' => round($incoming, 4),
            'closing'   => round($closing, 4),
        ];
    }
}
