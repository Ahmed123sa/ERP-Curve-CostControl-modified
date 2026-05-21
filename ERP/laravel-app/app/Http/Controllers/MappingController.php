<?php

namespace App\Http\Controllers;

use App\Models\ItemMapping;
use App\Models\LocationMapping;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class MappingController extends Controller
{
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
            'location_id' => 'required',
        ]);

        $clientId = $request->user()->current_client_id;

        $mapping = LocationMapping::updateOrCreate(
            [
                'client_id' => $clientId,
                'source_name' => $request->source_name,
            ],
            [
                'id' => (string) \Illuminate\Support\Str::uuid(),
                'location_id' => $request->location_id,
            ]
        );

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
}
