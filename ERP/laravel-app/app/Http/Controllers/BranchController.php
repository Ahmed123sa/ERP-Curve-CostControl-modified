<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\BranchWarehouseSource;
use App\Models\Warehouse;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

class BranchController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $clientId = $request->user()->current_client_id;
        $branches = Branch::where('client_id', $clientId)->get(['id', 'name', 'is_active', 'client_id']);
        $whBranches = Warehouse::where('type', 'branch')->get(['id', 'name', 'is_active', 'client_id']);
        // دمج المصدرين مع إزالة التكرار حسب الاسم (Branch له أولوية)
        $all = $branches->merge($whBranches)->unique('name')->values();
        return response()->json($all);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'name' => 'required|string|max:255',
        ]);

        $clientId = $request->user()->current_client_id;

        $branch = Branch::create([
            'id'        => (string) Str::uuid(),
            'client_id' => $clientId,
            'name'      => $request->name,
            'is_active' => true,
        ]);

        return response()->json($branch, 201);
    }

    public function update(Request $request, Branch $branch): JsonResponse
    {
        $request->validate([
            'name'      => 'sometimes|string|max:255',
            'is_active' => 'sometimes|boolean',
        ]);

        $branch->update($request->only(['name', 'is_active']));

        return response()->json($branch);
    }

    public function destroy(Branch $branch): JsonResponse
    {
        $branch->delete();
        return response()->json(['message' => 'Branch deleted']);
    }

    /**
     * عرض المخازن المرتبطة بالفرع
     */
    public function sources(Request $request, Branch $branch): JsonResponse
    {
        $sources = BranchWarehouseSource::where('branch_id', $branch->id)
            ->with('warehouse:id,name,type')
            ->orderBy('priority')
            ->get();

        return response()->json($sources);
    }

    /**
     * ربط/تحديث مخزن بالفرع (مع تحديد الأولوية)
     * يمكن تحديد item_id لتخصيص مخزن لصنف معين
     * أو تركه null ليشمل كل الأصناف
     */
    public function updateSources(Request $request, Branch $branch): JsonResponse
    {
        $request->validate([
            'warehouse_id' => 'required|uuid',
            'priority'     => 'sometimes|integer|min:1',
            'item_id'      => 'sometimes|nullable|uuid',
        ]);

        $clientId = $request->user()->current_client_id;

        // تأكد إن المخزن ينتمي للعميل
        $warehouse = Warehouse::where('id', $request->warehouse_id)
            ->where('client_id', $clientId)
            ->firstOrFail();

$existing = BranchWarehouseSource::where('branch_id', $branch->id)
             ->where('warehouse_id', $request->warehouse_id)
             ->where(function ($q) use ($request) {
                 $itemId = $request->input('item_id');
                 if ($itemId) {
                     $q->where('item_id', $itemId);
                 } else {
                     $q->whereNull('item_id');
                 }
             })
             ->first();

        if ($existing) {
            $existing->update(['priority' => $request->input('priority', $existing->priority)]);
            $source = $existing;
        } else {
            $source = BranchWarehouseSource::create([
                'id'          => (string) Str::uuid(),
                'branch_id'   => $branch->id,
                'warehouse_id'=> $request->warehouse_id,
                'item_id'     => $request->input('item_id'),
                'priority'    => $request->input('priority', 99),
            ]);
        }

        return response()->json($source, 201);
    }
}
