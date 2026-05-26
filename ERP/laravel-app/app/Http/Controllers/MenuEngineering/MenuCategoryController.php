<?php
namespace App\Http\Controllers\MenuEngineering;

use App\Http\Controllers\Controller;
use App\Models\MenuEngineering\MenuCategory;
use App\Models\MenuEngineering\MenuRecipe;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MenuCategoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $clientId = $request->user()->current_client_id;
        $menuId = $request->menu_id;

        if (!$menuId) {
            return response()->json([]);
        }

        // Categories from the per-menu table
        $tableCats = MenuCategory::where('client_id', $clientId)
            ->where('menu_id', $menuId)
            ->orderBy('sort_order')->orderBy('name')
            ->get(['id', 'name', 'sort_order']);

        // Categories from recipes in this menu (not in the table yet)
        $recipeNames = MenuRecipe::where('client_id', $clientId)
            ->where('menu_id', $menuId)
            ->whereNotNull('category')
            ->distinct()
            ->pluck('category');

        $tableNames = $tableCats->pluck('name')->toArray();
        $extraNames = $recipeNames->reject(fn($n) => in_array($n, $tableNames))->values();

        $extraCats = $extraNames->map(fn($n, $i) => [
            'id' => $n, 'name' => $n, 'sort_order' => $tableCats->count() + $i,
        ]);

        return response()->json($tableCats->concat($extraCats));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:100',
            'menu_id' => 'required|string',
            'sort_order' => 'nullable|integer',
        ]);
        $data['client_id'] = $request->user()->current_client_id;

        $exists = MenuCategory::where('client_id', $data['client_id'])
            ->where('menu_id', $data['menu_id'])
            ->where('name', $data['name'])->exists();
        if ($exists) {
            return response()->json(['message' => 'التصنيف موجود بالفعل في هذه القائمة'], 409);
        }

        $data['sort_order'] ??= 0;
        $cat = MenuCategory::create($data);
        return response()->json($cat, 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $clientId = $request->user()->current_client_id;
        $data = $request->validate(['name' => 'required|string|max:100', 'sort_order' => 'nullable|integer']);

        $cat = MenuCategory::where('client_id', $clientId)->find($id);
        if ($cat) {
            $oldName = $cat->name;
            $cat->update($data);
            MenuRecipe::where('client_id', $clientId)
                ->where('category', $oldName)
                ->update(['category' => $data['name']]);
        } else {
            MenuRecipe::where('client_id', $clientId)
                ->where('category', $id)
                ->update(['category' => $data['name']]);
        }

        return response()->json(['message' => 'updated']);
    }

    public function copy(Request $request, string $category): JsonResponse
    {
        $clientId = $request->user()->current_client_id;
        $data = $request->validate([
            'name' => 'required|string|max:100',
            'menu_id' => 'required|string',
        ]);
        $newCategoryName = $data['name'];
        $menuId = $data['menu_id'];

        // Resolve the actual category name from ID (UUID from MenuCategory table) or raw name (orphan)
        $cat = MenuCategory::where('id', $category)
            ->where('client_id', $clientId)
            ->where('menu_id', $menuId)->first();
        $categoryName = $cat ? $cat->name : $category;

        $recipes = MenuRecipe::where('client_id', $clientId)
            ->where('menu_id', $menuId)
            ->where('category', $categoryName)
            ->with('items')
            ->get();

        if ($recipes->isEmpty()) {
            return response()->json(['message' => 'لا توجد وصفات في هذا التصنيف'], 404);
        }

        $copied = 0;
        DB::transaction(function () use ($recipes, $newCategoryName, $clientId, $menuId, $request, &$copied) {
            foreach ($recipes as $recipe) {
                $newName = $recipe->name . ' (نسخة)';
                $suffix = 2;
                while (MenuRecipe::where('client_id', $clientId)
                    ->where('menu_id', $menuId)
                    ->where('name', $newName)->exists()
                ) {
                    $newName = $recipe->name . ' (نسخة ' . $suffix . ')';
                    $suffix++;
                }

                $newRecipe = MenuRecipe::create([
                    'client_id' => $clientId,
                    'branch_id' => $recipe->branch_id,
                    'menu_id' => $menuId,
                    'name' => $newName,
                    'code' => $recipe->code,
                    'category' => $newCategoryName,
                    'recipe_type' => $recipe->recipe_type,
                    'portions' => $recipe->portions,
                    'selling_price' => $recipe->selling_price,
                    'target_food_cost_pct' => $recipe->target_food_cost_pct,
                    'prep_instructions' => $recipe->prep_instructions,
                    'status' => 'draft',
                    'version' => 1,
                    'created_by' => $request->user()->id,
                ]);

                foreach ($recipe->items as $item) {
                    $newRecipe->items()->create([
                        'ingredient_id' => $item->ingredient_id,
                        'qty' => $item->qty,
                        'weight_g' => $item->weight_g,
                        'volume_ml' => $item->volume_ml,
                        'purchase_unit' => $item->purchase_unit,
                        'purchase_unit_price' => $item->purchase_unit_price,
                        'recipe_unit' => $item->recipe_unit,
                        'conversion_factor' => $item->conversion_factor,
                        'yield_pct' => $item->yield_pct,
                        'ep_cost' => $item->ep_cost,
                        'line_total' => $item->line_total,
                        'sort_order' => $item->sort_order,
                    ]);
                }

                $copied++;
            }
        });

        return response()->json(['message' => "تم نسخ $copied وصفة بنجاح", 'copied' => $copied], 201);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $clientId = $request->user()->current_client_id;

        $cat = MenuCategory::where('client_id', $clientId)->find($id);
        if ($cat) {
            $cat->delete();
            return response()->json(['message' => 'deleted']);
        }

        // Fallback: delete by name from recipes
        $name = $id;
        $updated = MenuRecipe::where('client_id', $clientId)
            ->where('category', $name)->update(['category' => null]);
        if ($updated) {
            return response()->json(['message' => 'deleted']);
        }

        return response()->json(['message' => 'التصنيف غير موجود'], 404);
    }
}
