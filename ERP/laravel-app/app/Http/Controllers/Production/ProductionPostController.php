<?php
namespace App\Http\Controllers\Production;

use App\Http\Controllers\Controller;
use App\Models\Production\DailyProduction;
use App\Models\Production\ProductionDeduction;
use App\Models\Production\Recipe;
use App\Models\Item;
use App\Models\DispatchOrder;
use App\Models\DispatchLine;
use App\Models\StockLedger;
use App\Services\StockLedgerService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductionPostController extends Controller
{
    public function __construct(private StockLedgerService $ledger) {}

    private function maybeDeduct(Request $request, string $recipeId, float $totalCost, string $orderId, string $warehouseId): void
    {
        $clientId = $request->user()->current_client_id;
        $month = $request->input('month', now()->format('Y-m'));

        $deduction = ProductionDeduction::where('client_id', $clientId)
            ->where('recipe_id', $recipeId)
            ->where('month', $month)
            ->where('deduct', true)
            ->first();

        if (!$deduction) return;

        StockLedger::create([
            'client_id'     => $clientId,
            'warehouse_id'  => $warehouseId,
            'item_id'       => $recipeId,
            'date'          => now()->toDateString(),
            'movement_type' => 'in',
            'voucher_type'  => 'purchase',
            'qty'           => 0,
            'unit_cost'     => 0,
            'total_cost'    => -$totalCost,
            'ref_type'      => 'dispatch_order',
            'ref_id'        => $orderId,
        ]);
    }

    private function calcRecipeCost(Recipe $recipe): float
    {
        $productionQty = $recipe->production_qty ?? 1;
        if ($productionQty <= 0) return 0;

        $totalCost = 0;
        foreach ($recipe->ingredients as $ing) {
            $qty = (float) $ing->qty;
            $unitCost = (float) (Item::find($ing->item_id)?->default_cost ?? 0);
            $totalCost += $qty * $unitCost;
        }

        return $totalCost / $productionQty;
    }

    public function preview(Request $request): JsonResponse
    {
        $clientId = $request->user()->current_client_id;
        $month = $request->query('month', now()->format('Y-m'));
        $start = Carbon::parse($month . '-01');
        $end = $start->copy()->endOfMonth();

        $recipes = Recipe::where('client_id', $clientId)
            ->with(['outputItem:id,name,unit', 'ingredients.item:id,name,unit', 'outputWarehouse:id,name'])
            ->get();

        $entries = DailyProduction::where('client_id', $clientId)
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->get();

        $summary = [];
        foreach ($recipes as $recipe) {
            $unitCost = $this->calcRecipeCost($recipe);

            // تجميع كميات الإنتاج: base recipe vs sizes
            $recipeEntries = $entries->where('recipe_id', $recipe->id);
            $baseEntries = $recipeEntries->whereNull('size_index');
            $totalQty = (float) $baseEntries->sum('qty');

            $itemSummary = [
                'recipe'          => $recipe->name,
                'output_item'     => $recipe->outputItem?->name,
                'output_warehouse'=> $recipe->outputWarehouse?->name,
                'total_qty'       => $totalQty,
                'unit_cost'       => round($unitCost, 4),
                'total_cost'      => round($unitCost * $totalQty, 2),
                'ingredients'     => [],
                'variants'        => [],
            ];

            foreach ($recipe->ingredients as $ing) {
                $totalIngredientQty = round($ing['qty'] * $totalQty, 4);
                $itemSummary['ingredients'][] = [
                    'item'    => $ing->item?->name,
                    'qty'     => $totalIngredientQty,
                    'per_unit'=> (float) $ing['qty'],
                ];
            }

            // تجميع كميات المقاسات من daily_production (حيث size_index != null)
            $sizes = $recipe->sizes ?? [];
            if (is_array($sizes) && count($sizes)) {
                foreach ($sizes as $idx => $size) {
                    $grams = (float) ($size['grams'] ?? 0);
                    if ($grams <= 0) continue;

                    $sizeEntries = $recipeEntries->where('size_index', $idx);
                    $sizeQty = (float) $sizeEntries->sum('qty');

                    $variantCost = ($grams / 1000) * $unitCost;
                    $itemSummary['variants'][] = [
                        'grams'        => $grams,
                        'item_id'      => $size['item_id'] ?? null,
                        'item_name'    => Item::find($size['item_id'])?->name ?? $recipe->outputItem?->name,
                        'selling_price'=> $size['selling_price'] ?? null,
                        'unit_cost'    => round($variantCost, 4),
                        'qty'          => $sizeQty,
                        'total_cost'   => round($variantCost * $sizeQty, 2),
                    ];
                }
            }

            if ($totalQty > 0 || count($itemSummary['variants']) > 0) {
                $summary[] = $itemSummary;
            }
        }

        return response()->json([
            'month'   => $month,
            'summary' => $summary,
            'total_recipes' => count($summary),
        ]);
    }

    public function post(Request $request): JsonResponse
    {
        $clientId = $request->user()->current_client_id;
        $userId   = $request->user()->id;
        $month    = $request->input('month', now()->format('Y-m'));
        $start    = Carbon::parse($month . '-01');
        $end      = $start->copy()->endOfMonth();
        $postDate = now()->toDateString();

        $recipes = Recipe::where('client_id', $clientId)
            ->with(['ingredients.item:id,name,unit', 'outputItem:id,name,unit,default_cost', 'outputWarehouse:id,name'])
            ->get();

        $allEntries = DailyProduction::where('client_id', $clientId)
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->get();

        $created = [];

        // حذف ترحيلات الإنتاج السابقة
        $existingOrders = DispatchOrder::where('client_id', $clientId)
            ->where('type', 'production')
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->pluck('id');

        foreach ($existingOrders as $orderId) {
            $this->ledger->reverseOrder($orderId);
            DispatchLine::where('order_id', $orderId)->delete();
            DispatchOrder::where('id', $orderId)->delete();
        }

        foreach ($recipes as $recipe) {
            $unitCost = max($this->calcRecipeCost($recipe), 0);

            $recipeEntries = $allEntries->where('recipe_id', $recipe->id);
            $sizes = $recipe->sizes ?? [];

            if (is_array($sizes) && count($sizes)) {
                // ── ترحيل المقاسات من الـ daily_production entries ──
                foreach ($sizes as $idx => $size) {
                    $grams = (float) ($size['grams'] ?? 0);
                    if ($grams <= 0) continue;

                    $sizeEntries = $recipeEntries->where('size_index', $idx);
                    $sizeQty = (float) $sizeEntries->sum('qty');
                    if ($sizeQty <= 0) continue;

                    $variantItemId = $size['item_id'] ?? $recipe->item_id;
                    $variantUnitCost = ($grams / 1000) * $unitCost;
                    $variantTotalCost = round($variantUnitCost * $sizeQty, 2);

                    $order = DispatchOrder::create([
                        'client_id'    => $clientId,
                        'type'         => 'production',
                        'date'         => $postDate,
                        'warehouse_id' => $recipe->output_warehouse_id,
                        'created_by'   => $userId,
                        'status'       => 'confirmed',
                    ]);

                    DispatchLine::create([
                        'order_id'     => $order->id,
                        'item_id'      => $variantItemId,
                        'warehouse_id' => $recipe->output_warehouse_id,
                        'qty'          => round($sizeQty, 2),
                        'total_cost'   => $variantTotalCost,
                        'unit_cost'    => round($variantUnitCost, 4),
                    ]);

                    $this->ledger->post(
                        clientId:     $clientId,
                        whId:         $recipe->output_warehouse_id,
                        itemId:       $variantItemId,
                        date:         $postDate,
                        movementType: 'in',
                        qty:          round($sizeQty, 2),
                        totalCost:    $variantTotalCost,
                        unitCost:     round($variantUnitCost, 4),
                        refType:      'dispatch_order',
                        refId:        $order->id,
                        voucherType:  'production'
                    );

                    $this->maybeDeduct($request, $recipe->id, $variantTotalCost, $order->id, $recipe->output_warehouse_id);

                    $created[] = [
                        'voucher_id' => $order->id,
                        'item'       => Item::find($variantItemId)?->name ?? $recipe->name,
                        'qty'        => round($sizeQty, 2),
                        'size_grams' => $grams,
                        'warehouse'  => $recipe->outputWarehouse?->name,
                    ];
                }
            } else {
                // ── وصفة بدون مقاسات: ترحيل عادي ──
                $baseEntries = $recipeEntries->whereNull('size_index');
                $totalQty = (float) $baseEntries->sum('qty');
                if ($totalQty <= 0) continue;

                $totalCost = round($unitCost * $totalQty, 2);

                $order = DispatchOrder::create([
                    'client_id'    => $clientId,
                    'type'         => 'production',
                    'date'         => $postDate,
                    'warehouse_id' => $recipe->output_warehouse_id,
                    'created_by'   => $userId,
                    'status'       => 'confirmed',
                ]);

                DispatchLine::create([
                    'order_id'     => $order->id,
                    'item_id'      => $recipe->item_id,
                    'warehouse_id' => $recipe->output_warehouse_id,
                    'qty'          => $totalQty,
                    'total_cost'   => $totalCost,
                    'unit_cost'    => round($unitCost, 4),
                ]);

                $this->ledger->post(
                    clientId:     $clientId,
                    whId:         $recipe->output_warehouse_id,
                    itemId:       $recipe->item_id,
                    date:         $postDate,
                    movementType: 'in',
                    qty:          $totalQty,
                    totalCost:    $totalCost,
                    unitCost:     round($unitCost, 4),
                    refType:      'dispatch_order',
                    refId:        $order->id,
                    voucherType:  'production'
                );

                $this->maybeDeduct($request, $recipe->id, $totalCost, $order->id, $recipe->output_warehouse_id);

                $created[] = [
                    'voucher_id' => $order->id,
                    'item'       => $recipe->name,
                    'qty'        => $totalQty,
                    'warehouse'  => $recipe->outputWarehouse?->name,
                ];
            }
        }

        return response()->json([
            'message' => sprintf('تم ترحيل %d منتج للحسابات', count($created)),
            'created' => $created,
        ]);
    }
}
