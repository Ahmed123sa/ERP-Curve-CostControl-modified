<?php
namespace App\Http\Controllers\Production;

use App\Http\Controllers\Controller;
use App\Models\Production\Recipe;
use App\Models\Production\RecipeIngredient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class RecipeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $clientId = $request->user()->current_client_id;
        $recipes = Recipe::where('client_id', $clientId)
            ->with(['ingredients', 'outputItem:id,name,unit', 'outputWarehouse:id,name'])
            ->orderBy('name')
            ->get();
        return response()->json($recipes);
    }

    public function store(Request $request): JsonResponse
    {
        $clientId = $request->user()->current_client_id;
        $data = $request->validate([
            'item_id'             => 'required|exists:items,id',
            'name'                => 'required|string|max:255',
            'unit'                => 'nullable|string|max:50',
            'production_qty'      => 'nullable|numeric|min:0',
            'selling_price'       => 'nullable|numeric|min:0',
            'output_warehouse_id' => 'nullable|exists:warehouses,id',
            'notes'               => 'nullable|string',
            'ingredients'         => 'required|array|min:1',
            'ingredients.*.item_id' => 'required|exists:items,id',
            'ingredients.*.qty'    => 'required|numeric|min:0',
            'ingredients.*.unit_cost' => 'nullable|numeric|min:0',
        ]);

        // لو المستخدم محددش مخزن استلام — نستخدم المخزن الافتراضي للصنف
        $outputWarehouseId = $data['output_warehouse_id'] ?? null;
        if (!$outputWarehouseId && ($data['item_id'] ?? null)) {
            $item = \App\Models\Item::find($data['item_id']);
            $outputWarehouseId = $item?->default_warehouse_id;
        }

        $recipe = Recipe::create([
            'client_id'          => $clientId,
            'item_id'            => $data['item_id'],
            'name'               => $data['name'],
            'unit'               => $data['unit'] ?? null,
            'production_qty'     => $data['production_qty'] ?? 1,
            'selling_price'      => $data['selling_price'] ?? null,
            'output_warehouse_id'=> $outputWarehouseId,
            'notes'              => $data['notes'] ?? null,
        ]);

        foreach ($data['ingredients'] as $ing) {
            RecipeIngredient::create([
                'id'        => (string) Str::uuid(),
                'recipe_id' => $recipe->id,
                'item_id'   => $ing['item_id'],
                'qty'       => $ing['qty'],
                'unit_cost' => $ing['unit_cost'] ?? null,
            ]);
        }

        $this->syncOutputCost($recipe, $data);

        $recipe->load(['ingredients', 'outputItem:id,name,unit', 'outputWarehouse:id,name']);
        return response()->json($recipe, 201);
    }

    public function show(Request $request, Recipe $recipe): JsonResponse
    {
        $recipe->load(['ingredients', 'outputItem:id,name,unit', 'outputWarehouse:id,name']);
        return response()->json($recipe);
    }

    public function update(Request $request, Recipe $recipe): JsonResponse
    {
        $data = $request->validate([
            'item_id'             => 'sometimes|exists:items,id',
            'name'                => 'sometimes|string|max:255',
            'unit'                => 'nullable|string|max:50',
            'production_qty'      => 'nullable|numeric|min:0',
            'selling_price'       => 'nullable|numeric|min:0',
            'output_warehouse_id' => 'nullable|exists:warehouses,id',
            'notes'               => 'nullable|string',
            'ingredients'         => 'sometimes|array|min:1',
            'ingredients.*.id'    => 'nullable|exists:production_recipe_ingredients,id',
            'ingredients.*.item_id' => 'required|exists:items,id',
            'ingredients.*.qty'    => 'required|numeric|min:0',
            'ingredients.*.unit_cost' => 'nullable|numeric|min:0',
        ]);

        $recipe->update($data);

        if (isset($data['ingredients'])) {
            $keepIds = [];
            foreach ($data['ingredients'] as $ing) {
                if (isset($ing['id'])) {
                    $ingredient = RecipeIngredient::find($ing['id']);
                    if ($ingredient && $ingredient->recipe_id === $recipe->id) {
                        $ingredient->update([
                            'item_id'   => $ing['item_id'],
                            'qty'       => $ing['qty'],
                            'unit_cost' => $ing['unit_cost'] ?? null,
                        ]);
                        $keepIds[] = $ing['id'];
                        continue;
                    }
                }
                $new = RecipeIngredient::create([
                    'id'        => (string) Str::uuid(),
                    'recipe_id' => $recipe->id,
                    'item_id'   => $ing['item_id'],
                    'qty'       => $ing['qty'],
                    'unit_cost' => $ing['unit_cost'] ?? null,
                ]);
                $keepIds[] = $new->id;
            }
            RecipeIngredient::where('recipe_id', $recipe->id)
                ->whereNotIn('id', $keepIds)
                ->delete();
        }

        $this->syncOutputCost($recipe, $data);

        $recipe->load(['ingredients', 'outputItem:id,name,unit', 'outputWarehouse:id,name']);
        return response()->json($recipe);
    }

    private function syncOutputCost(Recipe $recipe, array $data): void
    {
        $productionQty = $data['production_qty'] ?? $recipe->production_qty ?? 1;
        if ($productionQty <= 0) return;

        $ingredients = $data['ingredients'] ?? $recipe->ingredients()->get()->toArray();
        if (empty($ingredients)) return;

        $totalCost = 0;
        foreach ($ingredients as $ing) {
            $qty = (float) ($ing['qty'] ?? 0);
            $unitCost = isset($ing['unit_cost']) && $ing['unit_cost'] !== null
                ? (float) $ing['unit_cost']
                : (\App\Models\Item::find($ing['item_id'])?->default_cost ?? 0);
            $totalCost += $qty * $unitCost;
        }

        $pricePerKgOutput = $totalCost / $productionQty;
        \App\Models\Item::where('id', $recipe->item_id)->update(['default_cost' => $pricePerKgOutput]);
    }

    public function destroy(Recipe $recipe): JsonResponse
    {
        $recipe->delete();
        return response()->json(['message' => 'تم حذف الوصفة']);
    }
}
