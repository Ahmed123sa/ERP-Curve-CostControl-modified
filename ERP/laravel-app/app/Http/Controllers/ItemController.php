<?php

namespace App\Http\Controllers;

use App\Models\Item;
use App\Services\ActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

class ItemController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $clientId = $request->user()->current_client_id;
        $items = Item::where('client_id', $clientId)->orderBy('sort_order')->orderBy('name')->get();
        return response()->json($items);
    }

    public function store(Request $request): JsonResponse
    {
        $clientId = $request->user()->current_client_id;

        $request->validate([
            'name'     => ['required', 'string', 'max:255',
                function ($attr, $val, $fail) use ($clientId) {
                    if (Item::where('client_id', $clientId)->where('name', $val)->exists())
                        $fail('يوجد صنف بنفس الاسم بالفعل');
                },
            ],
            'unit'     => 'required|string|max:50',
            'category' => 'nullable|string|max:100',
            'default_cost' => 'nullable|numeric|min:0',
        ]);

        $item = Item::create([
            'id'        => (string) Str::uuid(),
            'client_id' => $clientId,
            'name'      => $request->name,
            'unit'      => $request->unit,
            'category'  => $request->category,
            'default_cost' => $request->default_cost ?? 0,
            'is_active' => true,
        ]);

        return response()->json($item, 201);
    }

    public function update(Request $request, Item $item): JsonResponse
    {
        $clientId = $request->user()->current_client_id;

        $request->validate([
            'name'      => ['sometimes', 'string', 'max:255',
                function ($attr, $val, $fail) use ($clientId, $item) {
                    if (Item::where('client_id', $clientId)->where('name', $val)->where('id', '!=', $item->id)->exists())
                        $fail('يوجد صنف بنفس الاسم بالفعل');
                },
            ],
            'unit'      => 'sometimes|string|max:50',
            'category'  => 'nullable|string|max:100',
            'is_active' => 'sometimes|boolean',
            'default_cost' => 'sometimes|numeric|min:0',
            'sort_order' => 'sometimes|integer',
        ]);

        $oldCost = $item->default_cost;
        $item->update($request->all());
        $item->refresh();

        if ($request->has('default_cost') && (float) $oldCost !== (float) $item->default_cost) {
            ActivityLogger::log(
                action:     'price_updated',
                entityType: 'Item',
                entityId:   $item->id,
                oldValues:  ['default_cost' => $oldCost],
                newValues:  ['default_cost' => (float) $item->default_cost, 'source' => 'manual_edit'],
            );
        }

        return response()->json($item);
    }

    public function destroy(Item $item): JsonResponse
    {
        $item->delete();
        return response()->json(['message' => 'Item deleted']);
    }

    public function bulkDelete(Request $request): JsonResponse
    {
        $clientId = $request->user()->current_client_id;
        \App\Models\ItemMapping::where('client_id', $clientId)->delete();
        Item::where('client_id', $clientId)->delete();
        return response()->json(['message' => 'All items deleted successfully']);
    }

    public function import(Request $request): JsonResponse
    {
        $request->validate(['file' => 'required|file']);
        
        $clientId = $request->user()->current_client_id;
        $path     = $request->file('file')->getRealPath();
        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($path);
        $sheet       = $spreadsheet->getActiveSheet();
        $rows        = $sheet->toArray(null, true, true, false);

        $imported = 0;
        foreach ($rows as $index => $row) {
            if ($index === 0) continue; // Skip header
            
            $name     = trim($row[0] ?? '');
            $unit     = trim($row[1] ?? 'قطعة');
            $category = trim($row[2] ?? 'خامات');
            $cost     = (float) trim($row[3] ?? '0');

            if ($name) {
                Item::updateOrCreate(
                    ['client_id' => $clientId, 'name' => $name],
                    [
                        'unit' => $unit, 
                        'category' => $category, 
                        'default_cost' => $cost, 
                        'sort_order' => $index, // Preserve Excel order
                        'is_active' => true
                    ]
                );
                $imported++;
            }
        }

        return response()->json(['message' => "تم استيراد {$imported} صنف بنجاح"]);
    }
}
