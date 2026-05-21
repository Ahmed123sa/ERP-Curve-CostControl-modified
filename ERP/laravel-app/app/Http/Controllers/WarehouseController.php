<?php

namespace App\Http\Controllers;

use App\Models\Warehouse;
use App\Models\Branch;
use App\Models\BranchWarehouseSource;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

class WarehouseController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $clientId = $request->user()->current_client_id;
        $warehouses = Warehouse::where('client_id', $clientId)->get();
        return response()->json($warehouses);
    }

    /**
     * إنشاء مخزن — لو فرعي بنشتغل branch مرتبط تلقائياً
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'type' => 'required|in:main,sub,branch',
        ]);

        $clientId = $request->user()->current_client_id;

        $warehouse = Warehouse::create([
            'id'        => (string) Str::uuid(),
            'client_id' => $clientId,
            'name'      => $request->name,
            'type'      => $request->type,
            'is_active' => true,
        ]);

        return response()->json($warehouse, 201);
    }

    public function show(Warehouse $warehouse): JsonResponse
    {
        return response()->json($warehouse);
    }

    public function update(Request $request, Warehouse $warehouse): JsonResponse
    {
        $request->validate([
            'name'      => 'sometimes|string|max:255',
            'type'      => 'sometimes|in:main,sub,branch',
            'is_active' => 'sometimes|boolean',
        ]);

        $warehouse->update($request->only(['name', 'type', 'is_active']));

        return response()->json($warehouse);
    }

    public function destroy(Warehouse $warehouse): JsonResponse
    {
        $warehouse->delete();
        return response()->json(['message' => 'Warehouse deleted']);
    }
}
