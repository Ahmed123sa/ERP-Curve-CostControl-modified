<?php
namespace App\Services\MenuEngineering;

use App\Models\MenuEngineering\MenuRecipe;
use App\Models\MenuEngineering\MenuRecipeItem;

class RecipeCostCalculationService
{
    public function calculateItem(MenuRecipeItem $item): MenuRecipeItem
    {
        $unitCost = $item->purchase_unit_price > 0 && $item->conversion_factor > 0
            ? $item->purchase_unit_price / $item->conversion_factor
            : 0;

        $yield = $item->yield_pct > 0 ? $item->yield_pct / 100 : 1;
        $item->ep_cost = $unitCost > 0 && $yield > 0
            ? round($unitCost / $yield, 4)
            : 0;

        $item->line_total = round($item->ep_cost * $item->qty, 4);
        return $item;
    }

    public function calculateItemFromArray(array $data): array
    {
        $unitCost = ($data['purchase_unit_price'] ?? 0) > 0 && ($data['conversion_factor'] ?? 0) > 0
            ? (float)$data['purchase_unit_price'] / (float)$data['conversion_factor']
            : 0;

        $yield = ($data['yield_pct'] ?? 100) > 0 ? (float)$data['yield_pct'] / 100 : 1;
        $data['ep_cost'] = $unitCost > 0 && $yield > 0
            ? round($unitCost / $yield, 4)
            : 0;

        $data['line_total'] = round($data['ep_cost'] * (float)($data['qty'] ?? 0), 4);

        if (isset($data['recipe_unit'])) {
            $ru = $data['recipe_unit'];
            $qty = (float)($data['qty'] ?? 0);
            if ($ru === 'g' || $ru === 'kg') {
                $data['weight_g'] = $ru === 'kg' ? $qty * 1000 : $qty;
                $data['volume_ml'] = null;
            } elseif ($ru === 'ml' || $ru === 'liter') {
                $data['volume_ml'] = $ru === 'liter' ? $qty * 1000 : $qty;
                $data['weight_g'] = null;
            }
        }

        return $data;
    }

    public function calculateRecipeTotals(MenuRecipe $recipe): array
    {
        $items = $recipe->items()->get();
        $totalCost = (float) $items->sum('line_total');
        $portions = max(1, (float) $recipe->portions);
        $costPerPortion = round($totalCost / $portions, 4);
        $sellingPrice = (float) $recipe->selling_price;
        $foodCostPct = $sellingPrice > 0 ? round(($totalCost / $sellingPrice) * 100, 2) : 0;
        $marginPct = round(100 - $foodCostPct, 2);
        $target = (float) ($recipe->target_food_cost_pct ?: 30);
        $idealPrice = $target > 0 ? round($totalCost / ($target / 100), 2) : 0;

        return compact('totalCost', 'costPerPortion', 'foodCostPct', 'marginPct', 'idealPrice');
    }
}
