<?php

namespace App\Http\Controllers;

use App\Models\ItemMapping;
use App\Models\LocationMapping;
use App\Models\Warehouse;
use App\Models\Branch;
use App\Models\DispatchLine;
use App\Models\StockLedger;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class MappingController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $clientId = $request->user()->current_client_id;

        $itemMappings = ItemMapping::where('client_id', $clientId)
            ->with('item:id,name,unit')
            ->get();

        $items = $itemMappings->map(fn($m) => [
            'id' => $m->id,
            'source_name' => $m->source_name,
            'item_id' => $m->item_id,
            'item' => $m->item ? ['id' => $m->item->id, 'name' => $m->item->name, 'unit' => $m->item->unit] : null,
            'context' => $m->context,
            'confidence' => $m->confidence,
        ]);

        $locMappings = LocationMapping::where('client_id', $clientId)->get();

        $whIds = $locMappings->where('target_type', 'warehouse')->pluck('target_id');
        $brIds = $locMappings->where('target_type', 'branch')->pluck('target_id');
        $warehouses = Warehouse::whereIn('id', $whIds)->get()->keyBy('id');
        $branches = Branch::whereIn('id', $brIds)->get()->keyBy('id');

        $locations = $locMappings->map(fn($m) => [
            'id' => $m->id,
            'source_name' => $m->source_name,
            'target_type' => $m->target_type,
            'target_id' => $m->target_id,
            'target_name' => $m->target_type === 'warehouse'
                ? ($warehouses[$m->target_id]->name ?? null)
                : ($branches[$m->target_id]->name ?? null),
            'voucher_type' => $m->voucher_type,
            'confidence' => $m->confidence,
        ]);

        return response()->json(['items' => $items, 'locations' => $locations]);
    }

    public function items(Request $request): JsonResponse
    {
        $clientId = $request->user()->current_client_id;
        $mappings = ItemMapping::where('client_id', $clientId)
            ->with('item:id,name,unit')
            ->get();
        return response()->json($mappings);
    }

    public function updateItem(Request $request): JsonResponse
    {
        $request->validate([
            'source_name' => 'required|string',
            'item_id' => 'required|exists:items,id',
        ]);

        $clientId = $request->user()->current_client_id;

        $mapping = ItemMapping::updateOrCreate(
            [
                'client_id' => $clientId,
                'source_name' => $request->source_name,
            ],
            [
                'id' => (string) \Illuminate\Support\Str::uuid(),
                'item_id' => $request->item_id,
            ]
        );

        return response()->json($mapping);
    }

    public function locations(Request $request): JsonResponse
    {
        $clientId = $request->user()->current_client_id;
        $mappings = LocationMapping::where('client_id', $clientId)->get();
        return response()->json($mappings);
    }

    public function updateLocation(Request $request): JsonResponse
    {
        $request->validate([
            'source_name' => 'required|string',
            'target_type' => 'required|string|in:warehouse,branch',
            'target_id' => 'required|string',
        ]);

        $clientId = $request->user()->current_client_id;

        $mapping = LocationMapping::where('client_id', $clientId)
            ->where('source_name', $request->source_name)
            ->first();

        $data = [
            'target_type' => $request->target_type,
            'target_id' => $request->target_id,
            'confidence' => 100,
        ];

        if ($mapping) {
            $mapping->update($data);
        } else {
            $mapping = LocationMapping::create(
                ['id' => (string) \Illuminate\Support\Str::uuid(), 'client_id' => $clientId, 'source_name' => $request->source_name] + $data
            );
        }

        return response()->json($mapping);
    }

    public function deleteItem(ItemMapping $id): JsonResponse
    {
        $id->delete();
        return response()->json(['message' => 'Mapping deleted']);
    }

    public function deleteLocation(LocationMapping $id): JsonResponse
    {
        $id->delete();
        return response()->json(['message' => 'Mapping deleted']);
    }

    public function remapItem(Request $request): JsonResponse
    {
        $request->validate([
            'source_name' => 'required|string',
            'old_item_id' => 'required|string',
            'new_item_id' => 'required|string',
        ]);

        $clientId = $request->user()->current_client_id;
        $sourceName = $request->source_name;
        $oldItemId = $request->old_item_id;
        $newItemId = $request->new_item_id;

        DB::transaction(function () use ($clientId, $sourceName, $oldItemId, $newItemId) {
            ItemMapping::where('client_id', $clientId)
                ->where('source_name', $sourceName)
                ->update(['item_id' => $newItemId]);

            $lines = DispatchLine::where('client_id', $clientId)
                ->where('source_name', $sourceName)
                ->where('item_id', $oldItemId)
                ->get();

            $lineIds = $lines->pluck('id');
            $orderIds = $lines->pluck('order_id')->unique()->filter();

            if ($lineIds->isNotEmpty()) {
                DispatchLine::whereIn('id', $lineIds)->update(['item_id' => $newItemId]);

                StockLedger::whereIn('dispatch_line_id', $lineIds)
                    ->update(['item_id' => $newItemId]);
            }

            if ($orderIds->isNotEmpty()) {
                StockLedger::where('ref_type', 'dispatch_order')
                    ->whereIn('ref_id', $orderIds)
                    ->whereNull('dispatch_line_id')
                    ->where('item_id', $oldItemId)
                    ->update(['item_id' => $newItemId]);
            }

            $affected = collect();
            foreach ($lines as $l) {
                $affected->push(['wh' => $l->warehouse_id, 'month' => \Carbon\Carbon::parse($l->date ?: $l->created_at)->format('Y-m')]);
            }
            $ledgerEntries = StockLedger::whereIn('dispatch_line_id', $lineIds)->get();
            foreach ($ledgerEntries as $l) {
                $affected->push(['wh' => $l->warehouse_id, 'month' => \Carbon\Carbon::parse($l->date ?: $l->created_at)->format('Y-m')]);
            }
            $affected = $affected->unique()->values();

            $calc = app(\App\Services\CostCalculationService::class);
            foreach ($affected as $a) {
                $calc->generateMonthlyClosing($clientId, $a['wh'], $a['month']);
            }
        });

        return response()->json(['message' => 'تم إعادة ربط الإدخالات السابقة']);
    }
}
