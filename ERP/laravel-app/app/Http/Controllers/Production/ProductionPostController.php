<?php
namespace App\Http\Controllers\Production;

use App\Http\Controllers\Controller;
use App\Models\Production\DailyProduction;
use App\Models\Production\Recipe;
use App\Models\DispatchOrder;
use App\Models\DispatchLine;
use App\Services\StockLedgerService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductionPostController extends Controller
{
    public function __construct(private StockLedgerService $ledger) {}

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
            ->get()
            ->groupBy('recipe_id');

        $summary = [];
        foreach ($recipes as $recipe) {
            $recipeEntries = $entries->get($recipe->id, collect());
            $totalQty = (float) $recipeEntries->sum('qty');
            if ($totalQty <= 0) continue;

            $itemSummary = [
                'recipe'          => $recipe->name,
                'output_item'     => $recipe->outputItem?->name,
                'output_warehouse'=> $recipe->outputWarehouse?->name,
                'total_qty'       => $totalQty,
                'output_voucher'  => [
                    'type' => 'production',
                    'warehouse_id' => $recipe->output_warehouse_id,
                    'qty'  => $totalQty,
                ],
                'ingredients' => [],
            ];

            foreach ($recipe->ingredients as $ing) {
                $totalIngredientQty = round($ing['qty'] * $totalQty, 4);
                $itemSummary['ingredients'][] = [
                    'item'    => $ing->item?->name,
                    'qty'     => $totalIngredientQty,
                    'per_unit'=> (float) $ing['qty'],
                ];
            }

            $summary[] = $itemSummary;
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

        $entries = DailyProduction::where('client_id', $clientId)
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->get()
            ->groupBy('recipe_id');

        $created = [];

        // حذف كل ترحيلات الإنتاج السابقة للشهر نفسه (عشان ما يتكررش)
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
            $recipeEntries = $entries->get($recipe->id, collect());
            $totalQty = (float) $recipeEntries->sum('qty');
            if ($totalQty <= 0) continue;

            // سعر المنتج من تحديث الوصفة (default_cost = تكلفة كيلو المنتج)
            $unitCost = (float) ($recipe->outputItem?->default_cost ?? 0);
            $totalCost = round($unitCost * $totalQty, 2);

            // إنشاء إذن إنتاج (المنتج النهائي ← المخزن المستلم)
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
                'unit_cost'    => $unitCost,
            ]);

            $this->ledger->post(
                clientId:     $clientId,
                whId:         $recipe->output_warehouse_id,
                itemId:       $recipe->item_id,
                date:         $postDate,
                movementType: 'in',
                qty:          $totalQty,
                totalCost:    $totalCost,
                unitCost:     $unitCost,
                refType:      'dispatch_order',
                refId:        $order->id,
                voucherType:  'production'
            );

            $created[] = [
                'voucher_id' => $order->id,
                'item'       => $recipe->name,
                'qty'        => $totalQty,
                'warehouse'  => $recipe->outputWarehouse?->name,
            ];
        }

        return response()->json([
            'message' => sprintf('تم ترحيل %d منتج للحسابات', count($created)),
            'created' => $created,
        ]);
    }
}
