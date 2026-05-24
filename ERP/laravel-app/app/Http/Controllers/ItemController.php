<?php

namespace App\Http\Controllers;

use App\Models\Item;
use App\Models\Warehouse;
use App\Services\ActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
            'min_stock_level' => 'nullable|numeric|min:0',
        ]);

        $item = Item::create([
            'id'        => (string) Str::uuid(),
            'client_id' => $clientId,
            'name'      => $request->name,
            'unit'      => $request->unit,
            'category'  => $request->category,
            'default_cost' => $request->default_cost ?? 0,
            'min_stock_level' => $request->min_stock_level,
            'sort_order' => $request->sort_order ?? Item::where('client_id', $clientId)->max('sort_order') + 1,
            'is_active' => true,
        ]);

        // Handle insert before/after
        if ($request->after_id) {
            $ref = Item::find($request->after_id);
            if ($ref) {
                $item->update(['sort_order' => $ref->sort_order + 1]);
                Item::where('client_id', $clientId)->where('id', '!=', $item->id)->where('sort_order', '>=', $ref->sort_order + 1)->increment('sort_order');
            }
        } elseif ($request->before_id) {
            $ref = Item::find($request->before_id);
            if ($ref) {
                Item::where('client_id', $clientId)->where('sort_order', '>=', $ref->sort_order)->increment('sort_order');
                $item->update(['sort_order' => $ref->sort_order]);
            }
        }

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
            'min_stock_level' => 'nullable|numeric|min:0',
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
        $whLookup = Warehouse::where('client_id', $clientId)->pluck('id', 'name');
        foreach ($rows as $index => $row) {
            if ($index === 0) continue;

            $name     = trim($row[0] ?? '');
            $unit     = trim($row[1] ?? 'قطعة');
            $cost     = (float) trim($row[2] ?? '0');
            $minStock = $row[3] !== null && $row[3] !== '' ? (float) trim($row[3]) : null;
            $whName   = trim($row[4] ?? '');

            if ($name) {
                $data = [
                    'unit' => $unit,
                    'default_cost' => $cost,
                    'min_stock_level' => $minStock,
                    'sort_order' => $index,
                    'is_active' => true,
                ];
                if ($whName && isset($whLookup[$whName])) {
                    $data['default_warehouse_id'] = $whLookup[$whName];
                }
                Item::updateOrCreate(
                    ['client_id' => $clientId, 'name' => $name],
                    $data
                );
                $imported++;
            }
        }

        return response()->json(['message' => "تم استيراد {$imported} صنف بنجاح"]);
    }

    public function importStockLevels(Request $request): JsonResponse
    {
        $request->validate(['file' => 'required|file']);

        $clientId = $request->user()->current_client_id;
        $path     = $request->file('file')->getRealPath();
        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($path);
        $sheet       = $spreadsheet->getActiveSheet();
        $rows        = $sheet->toArray(null, true, true, false);

        $updated = 0;
        foreach ($rows as $index => $row) {
            if ($index === 0) continue;

            $name     = trim($row[0] ?? '');
            $minStock = $row[1] !== null && $row[1] !== '' ? (float) trim($row[1]) : null;

            if ($name) {
                $item = Item::where('client_id', $clientId)->where('name', $name)->first();
                if ($item) {
                    $item->update(['min_stock_level' => $minStock]);
                    $updated++;
                }
            }
        }

        return response()->json(['message' => "تم تحديث الحد الأدنى لـ {$updated} صنف"]);
    }

    public function exportExcel(Request $request): StreamedResponse
    {
        $clientId = $request->user()->current_client_id;
        $items = Item::where('client_id', $clientId)->orderBy('sort_order')->orderBy('name')->get();
        $whLookup = Warehouse::where('client_id', $clientId)->pluck('name', 'id');

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet()->setRightToLeft(true);

        $headers = ['#', 'الاسم', 'الوحدة', 'السعر', 'الحد الأدنى', 'المخزن الافتراضي', 'التصنيف'];
        foreach ($headers as $ci => $h) {
            $col = Coordinate::stringFromColumnIndex($ci + 1);
            $sheet->setCellValue($col . '1', $h);
            $sheet->getStyle($col . '1')->getFont()->setBold(true);
        }

        foreach ($items as $i => $item) {
            $row = $i + 2;
            $sheet->setCellValue('A' . $row, $i + 1);
            $sheet->setCellValue('B' . $row, $item->name);
            $sheet->setCellValue('C' . $row, $item->unit);
            $sheet->setCellValue('D' . $row, (float) $item->default_cost);
            $sheet->setCellValue('E' . $row, $item->min_stock_level ?? '');
            $sheet->setCellValue('F' . $row, $item->default_warehouse_id ? ($whLookup[$item->default_warehouse_id] ?? '') : 'الرئيسي (تلقائي)');
            $sheet->setCellValue('G' . $row, $item->category ?? '');
        }

        foreach (range('A', 'G') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $writer = new Xlsx($spreadsheet);
        return response()->streamDownload(function () use ($writer) {
            $writer->save('php://output');
        }, 'items.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    public function moveBottom(Request $request, Item $item): JsonResponse
    {
        $clientId = $request->user()->current_client_id;
        $maxSort = (int) Item::where('client_id', $clientId)->max('sort_order');
        $item->update(['sort_order' => $maxSort + 1]);
        return response()->json(['message' => 'تم نقل الصنف إلى الأسفل']);
    }

    public function moveUp(Request $request, Item $item): JsonResponse
    {
        $clientId = $request->user()->current_client_id;
        $above = Item::where('client_id', $clientId)
            ->where('sort_order', '<', $item->sort_order)
            ->orderBy('sort_order', 'desc')
            ->first();
        if (!$above) {
            return response()->json(['message' => 'الصنف في البداية بالفعل'], 400);
        }
        $temp = $item->sort_order;
        $item->update(['sort_order' => $above->sort_order]);
        $above->update(['sort_order' => $temp]);
        return response()->json(['message' => 'تم نقل الصنف إلى الأعلى']);
    }
}
