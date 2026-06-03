<?php
namespace App\Http\Controllers\Production;

use App\Http\Controllers\Controller;
use App\Models\Production\DailyProduction;
use App\Models\Production\ProductionDeduction;
use App\Models\Production\Recipe;
use App\Models\Item;
use App\Models\Warehouse;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class DailyProductionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $clientId = $request->user()->current_client_id;
        $month = $request->query('month', now()->format('Y-m'));
        $start = Carbon::parse($month . '-01');
        $end = $start->copy()->endOfMonth();

        $recipes = Recipe::where('client_id', $clientId)
            ->with(['outputItem:id,name,unit', 'outputWarehouse:id,name'])
            ->orderBy('name')
            ->get();

        // توسيع الوصفات إلى قائمة تشمل المقاسات
        $expanded = [];
        foreach ($recipes as $recipe) {
            // السطر الأساسي للوصفة
            $expanded[] = [
                'id'          => $recipe->id,
                'name'        => $recipe->name,
                'unit'        => $recipe->unit,
                'outputItem'  => $recipe->outputItem,
                'outputWarehouse' => $recipe->outputWarehouse,
                'is_size'     => false,
                'size_index'  => null,
                'grams'       => null,
                'item_id'     => $recipe->item_id,
            ];

            // سطور المقاسات
            $sizes = $recipe->sizes ?? [];
            if (is_array($sizes)) {
                foreach ($sizes as $idx => $size) {
                    $sizeItem = $size['item_id'] ? Item::find($size['item_id']) : null;
                    $expanded[] = [
                        'id'          => $recipe->id . '::size::' . $idx,
                        'name'        => $sizeItem?->name ?? $recipe->name . ' (' . ($size['grams'] ?? 0) . 'g)',
                        'unit'        => 'قطعة',
                        'outputItem'  => $sizeItem ? ['id' => $sizeItem->id, 'name' => $sizeItem->name, 'unit' => $sizeItem->unit] : null,
                        'outputWarehouse' => $recipe->outputWarehouse,
                        'is_size'     => true,
                        'size_index'  => $idx,
                        'grams'       => $size['grams'] ?? 0,
                        'item_id'     => $size['item_id'] ?? $recipe->item_id,
                        'recipe_id'   => $recipe->id,
                    ];
                }
            }
        }

        // إضافة الأصناف اليدوية (الوارد من المعالجات بدون وصفة)
        $manualIds = DailyProduction::where('client_id', $clientId)
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->whereNotNull('recipe_id')
            ->pluck('recipe_id')
            ->unique()
            ->reject(fn($rid) => $recipes->contains('id', $rid))
            ->values();

        foreach ($manualIds as $rid) {
            $item = Item::find($rid);
            if (!$item) continue;
            $wh = $item->default_warehouse_id
                ? (object) ['id' => $item->default_warehouse_id, 'name' => Warehouse::find($item->default_warehouse_id)?->name]
                : null;
            $expanded[] = [
                'id'          => $rid,
                'name'        => $item->name,
                'unit'        => $item->unit,
                'outputItem'  => ['id' => $item->id, 'name' => $item->name, 'unit' => $item->unit],
                'outputWarehouse' => $wh,
                'is_size'     => false,
                'size_index'  => null,
                'grams'       => null,
                'item_id'     => $item->id,
            ];
        }

        // تحميل آخر اختيارات المخازن لكل recipe_id
        $warehouseSelections = DailyProduction::where('client_id', $clientId)
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->whereNotNull('warehouse_id')
            ->select('recipe_id', 'warehouse_id')
            ->get()
            ->groupBy('recipe_id')
            ->map(fn($items) => $items->last()->warehouse_id);

        // إضافة selectedWarehouse لكل صف موسع
        foreach ($expanded as &$row) {
            $rid = $row['is_size'] ? $row['recipe_id'] : $row['id'];
            if ($row['is_size']) {
                // المقاسات تشارك نفس warehouse بتاع الوصفة الأم
                $row['selectedWarehouse'] = $warehouseSelections[$rid] ?? $row['outputWarehouse']?->id ?? null;
            } else {
                $row['selectedWarehouse'] = $warehouseSelections[$rid] ?? $row['outputWarehouse']?->id ?? null;
            }
        }
        unset($row);

        // قراءة الإنتاج اليومي — مفتاح مركب recipe_id|size_index
        $entries = DailyProduction::where('client_id', $clientId)
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->get()
            ->groupBy(fn($e) => $e->recipe_id . '|' . ($e->size_index !== null ? 'size_' . $e->size_index : 'base') . '|' . $e->date->format('Y-m-d'));

        $data = [];
        foreach ($entries as $key => $items) {
            $parts = explode('|', $key);
            $recipeId = $parts[0];
            $sizeKey = $parts[1];
            $date = $parts[2];
            $day = (int) Carbon::parse($date)->format('d');

            $entryId = $recipeId . ($sizeKey !== 'base' ? '::size::' . str_replace('size_', '', $sizeKey) : '');
            if (!isset($data[$entryId])) $data[$entryId] = [];
            $data[$entryId][$day] = (float) $items->sum('qty');
        }

        return response()->json([
            'month'      => $month,
            'recipes'    => $expanded,
            'data'       => $data,
            'warehouses' => Warehouse::where('client_id', $clientId)->where('is_active', true)->get(['id', 'name']),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $clientId = $request->user()->current_client_id;
        $data = $request->validate([
            'month'    => 'required|date_format:Y-m',
            'entries'  => 'required|array',
            'entries.*.recipe_id'    => 'required|string',
            'entries.*.day'          => 'required|integer|between:1,31',
            'entries.*.qty'          => 'required|numeric|min:0',
            'entries.*.warehouse_id' => 'nullable|string|exists:warehouses,id',
        ]);

        $month = $data['month'];
        $yearMonth = explode('-', $month);
        $year = $yearMonth[0];
        $monthNum = $yearMonth[1];

        foreach ($data['entries'] as $entry) {
            $date = sprintf('%s-%s-%02d', $year, $monthNum, (int) $entry['day']);

            // استخراج recipe_id و size_index من المفتاح المركب
            $recipeId = $entry['recipe_id'];
            $sizeIndex = null;
            if (str_contains($recipeId, '::size::')) {
                $parts = explode('::size::', $recipeId);
                $recipeId = $parts[0];
                $sizeIndex = (int) $parts[1];
            }

            if ($entry['qty'] > 0) {
                DailyProduction::updateOrCreate(
                    [
                        'client_id'  => $clientId,
                        'recipe_id'  => $recipeId,
                        'size_index' => $sizeIndex,
                        'date'       => $date,
                    ],
                    [
                        'qty' => $entry['qty'],
                        'warehouse_id' => $entry['warehouse_id'] ?? null,
                    ]
                );
            } else {
                DailyProduction::where('client_id', $clientId)
                    ->where('recipe_id', $recipeId)
                    ->where('size_index', $sizeIndex)
                    ->where('date', $date)
                    ->delete();
            }
        }

        return response()->json(['message' => 'تم حفظ الإنتاج اليومي']);
    }

    public function deductions(Request $request): JsonResponse
    {
        $clientId = $request->user()->current_client_id;
        $month = $request->query('month', now()->format('Y-m'));

        $items = ProductionDeduction::where('client_id', $clientId)
            ->where('month', $month)
            ->get()
            ->keyBy('recipe_id')
            ->map(fn($d) => $d->deduct);

        return response()->json($items);
    }

    public function toggleDeduction(Request $request): JsonResponse
    {
        $clientId = $request->user()->current_client_id;
        $data = $request->validate([
            'recipe_id' => 'required|string',
            'month'     => 'required|date_format:Y-m',
            'deduct'    => 'required|boolean',
        ]);

        if ($data['deduct']) {
            ProductionDeduction::updateOrCreate(
                [
                    'client_id' => $clientId,
                    'recipe_id' => $data['recipe_id'],
                    'month'     => $data['month'],
                ],
                ['deduct' => true]
            );
        } else {
            ProductionDeduction::where('client_id', $clientId)
                ->where('recipe_id', $data['recipe_id'])
                ->where('month', $data['month'])
                ->delete();
        }

        return response()->json(['message' => 'تم']);
    }
}
