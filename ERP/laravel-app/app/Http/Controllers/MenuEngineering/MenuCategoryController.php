<?php
namespace App\Http\Controllers\MenuEngineering;

use App\Http\Controllers\Controller;
use App\Models\MenuEngineering\MenuCategory;
use App\Models\MenuEngineering\MenuRecipe;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
        $cat = MenuCategory::where('client_id', $request->user()->current_client_id)
            ->findOrFail($id);
        $data = $request->validate(['name' => 'required|string|max:100', 'sort_order' => 'nullable|integer']);
        $cat->update($data);
        return response()->json($cat);
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
