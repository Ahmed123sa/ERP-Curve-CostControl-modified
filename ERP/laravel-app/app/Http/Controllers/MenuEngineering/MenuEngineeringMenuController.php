<?php
namespace App\Http\Controllers\MenuEngineering;

use App\Http\Controllers\Controller;
use App\Models\MenuEngineering\MenuEngineeringMenu;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
}
