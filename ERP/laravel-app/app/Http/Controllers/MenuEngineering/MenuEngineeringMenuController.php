<?php
namespace App\Http\Controllers\MenuEngineering;

use App\Http\Controllers\Controller;
use App\Models\MenuEngineering\MenuCategory;
use App\Models\MenuEngineering\MenuEngineeringMenu;
use App\Models\MenuEngineering\MenuRecipe;
use App\Models\MenuEngineering\MenuRecipeItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MenuEngineeringMenuController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $clientId = $request->user()->current_client_id;
        $branchId = $request->branch_id;

        $menus = MenuEngineeringMenu::where('client_id', $clientId)
            ->when($branchId, fn($q) => $q->where('branch_id', $branchId))
            ->orderBy('sort_order')->orderBy('name')
            ->get();

        return response()->json($menus);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:100',
            'branch_id' => 'required|string',
            'sort_order' => 'nullable|integer',
        ]);
        $data['client_id'] = $request->user()->current_client_id;

        $exists = MenuEngineeringMenu::where('client_id', $data['client_id'])
            ->where('branch_id', $data['branch_id'])
            ->where('name', $data['name'])->exists();
        if ($exists) {
            return response()->json(['message' => 'القائمة موجودة بالفعل لهذا الفرع'], 409);
        }

        $menu = MenuEngineeringMenu::create($data);
        return response()->json($menu, 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $menu = MenuEngineeringMenu::findOrFail($id);
        $data = $request->validate([
            'name' => 'sometimes|string|max:100',
            'sort_order' => 'nullable|integer',
        ]);

        if (isset($data['name']) && $data['name'] !== $menu->name) {
            $exists = MenuEngineeringMenu::where('client_id', $menu->client_id)
                ->where('branch_id', $menu->branch_id)
                ->where('name', $data['name'])
                ->where('id', '!=', $menu->id)->exists();
            if ($exists) {
                return response()->json(['message' => 'القائمة موجودة بالفعل لهذا الفرع'], 409);
            }
        }

        $menu->update($data);
        return response()->json($menu);
    }

    public function destroy(string $id): JsonResponse
    {
        $menu = MenuEngineeringMenu::findOrFail($id);
        $menu->recipes()->update(['menu_id' => null]);
        $menu->delete();
        return response()->json(['message' => 'deleted']);
    }

    public function copy(Request $request, string $id): JsonResponse
    {
        $clientId = $request->user()->current_client_id;
        $sourceMenu = MenuEngineeringMenu::where('client_id', $clientId)->findOrFail($id);

        $data = $request->validate([
            'name' => 'required|string|max:100',
            'target_branch_id' => 'required|string',
        ]);

        $exists = MenuEngineeringMenu::where('client_id', $clientId)
            ->where('branch_id', $data['target_branch_id'])
            ->where('name', $data['name'])->exists();
        if ($exists) {
            return response()->json(['message' => 'القائمة موجودة بالفعل لهذا الفرع'], 409);
        }

        DB::transaction(function () use ($sourceMenu, $data, $clientId, $request) {
            $newMenu = MenuEngineeringMenu::create([
                'client_id' => $clientId,
                'branch_id' => $data['target_branch_id'],
                'name' => $data['name'],
                'sort_order' => $sourceMenu->sort_order,
            ]);

            $categories = MenuCategory::where('menu_id', $sourceMenu->id)->get();
            foreach ($categories as $cat) {
                MenuCategory::create([
                    'client_id' => $clientId,
                    'menu_id' => $newMenu->id,
                    'name' => $cat->name,
                    'sort_order' => $cat->sort_order,
                ]);
            }

            $recipes = MenuRecipe::where('menu_id', $sourceMenu->id)->get();
            foreach ($recipes as $recipe) {
                $newRecipe = MenuRecipe::create([
                    'client_id' => $clientId,
                    'branch_id' => $data['target_branch_id'],
                    'menu_id' => $newMenu->id,
                    'name' => $recipe->name,
                    'code' => $recipe->code,
                    'category' => $recipe->category,
                    'recipe_type' => $recipe->recipe_type,
                    'portions' => $recipe->portions,
                    'selling_price' => $recipe->selling_price,
                    'target_food_cost_pct' => $recipe->target_food_cost_pct,
                    'prep_instructions' => $recipe->prep_instructions,
                    'status' => 'draft',
                    'version' => 1,
                    'created_by' => $request->user()->id,
                ]);

                $items = MenuRecipeItem::where('recipe_id', $recipe->id)->get();
                foreach ($items as $item) {
                    MenuRecipeItem::create([
                        'recipe_id' => $newRecipe->id,
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
            }
        });

        return response()->json(['message' => 'تم نسخ القائمة بنجاح']);
    }
}
