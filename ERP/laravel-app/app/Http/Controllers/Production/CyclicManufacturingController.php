<?php
namespace App\Http\Controllers\Production;

use App\Http\Controllers\Controller;
use App\Models\Production\CyclicManufacturing;
use App\Models\Production\CyclicManufacturingInput;
use App\Models\Production\DailyProduction;
use App\Models\Item;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CyclicManufacturingController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $clientId = $request->user()->current_client_id;
        $month = $request->query('month', now()->format('Y-m'));

        $records = CyclicManufacturing::where('client_id', $clientId)
            ->where('month', $month)
            ->with('inputs.item:id,name,unit', 'item:id,name,unit')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($records);
    }

    public function show(string $id): JsonResponse
    {
        $record = CyclicManufacturing::with('inputs.item:id,name,unit', 'item:id,name,unit')
            ->findOrFail($id);
        return response()->json($record);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'item_id'            => 'required|string',
            'month'              => 'required|date_format:Y-m',
            'total_output_qty'   => 'required|numeric|min:0',
            'output_ratio'       => 'nullable|numeric|min:0',
            'output_qty_json'    => 'nullable|array',
            'inputs'             => 'required|array|min:1',
            'inputs.*.item_id'   => 'required|string',
            'inputs.*.unit'      => 'nullable|string|max:50',
            'inputs.*.cost_per_unit' => 'required|numeric|min:0',
            'inputs.*.qty_json'  => 'required|array',
            'inputs.*.total_qty' => 'required|numeric|min:0',
        ]);

        $clientId = $request->user()->current_client_id;

        $totalInputCost = 0;
        foreach ($data['inputs'] as &$in) {
            $in['line_total'] = (float) $in['total_qty'] * (float) $in['cost_per_unit'];
            $totalInputCost += $in['line_total'];
        }
        unset($in);

        $outputQty = (float) $data['total_output_qty'];
        $avgCost = $outputQty > 0 ? $totalInputCost / $outputQty : 0;

        $record = DB::transaction(function () use ($clientId, $data, $totalInputCost, $avgCost) {
            $record = CyclicManufacturing::create([
                'id'               => (string) Str::orderedUuid(),
                'client_id'        => $clientId,
                'item_id'          => $data['item_id'],
                'month'            => $data['month'],
                'total_output_qty' => $data['total_output_qty'],
                'total_input_cost' => $totalInputCost,
                'avg_unit_cost'    => round($avgCost, 4),
                'output_ratio'     => $data['output_ratio'] ?? 1,
                'output_qty_json'  => $data['output_qty_json'] ?? null,
                'posted_to_production' => false,
            ]);

            foreach ($data['inputs'] as $in) {
                CyclicManufacturingInput::create([
                    'id'            => (string) Str::orderedUuid(),
                    'cyclic_id'     => $record->id,
                    'item_id'       => $in['item_id'],
                    'unit'          => $in['unit'] ?? null,
                    'cost_per_unit' => $in['cost_per_unit'],
                    'qty_json'      => $in['qty_json'],
                    'total_qty'     => $in['total_qty'],
                    'line_total'    => $in['line_total'],
                ]);
            }

            return $record->load('inputs.item:id,name,unit');
        });

        return response()->json($record, 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $record = CyclicManufacturing::findOrFail($id);

        $data = $request->validate([
            'total_output_qty'   => 'required|numeric|min:0',
            'output_ratio'       => 'nullable|numeric|min:0',
            'output_qty_json'    => 'nullable|array',
            'inputs'             => 'required|array|min:1',
            'inputs.*.id'        => 'nullable|string',
            'inputs.*.item_id'   => 'required|string',
            'inputs.*.unit'      => 'nullable|string|max:50',
            'inputs.*.cost_per_unit' => 'required|numeric|min:0',
            'inputs.*.qty_json'  => 'required|array',
            'inputs.*.total_qty' => 'required|numeric|min:0',
        ]);

        $totalInputCost = 0;
        foreach ($data['inputs'] as &$in) {
            $in['line_total'] = (float) $in['total_qty'] * (float) $in['cost_per_unit'];
            $totalInputCost += $in['line_total'];
        }
        unset($in);

        $outputQty = (float) $data['total_output_qty'];
        $avgCost = $outputQty > 0 ? $totalInputCost / $outputQty : 0;

        DB::transaction(function () use ($record, $data, $totalInputCost, $avgCost) {
            $record->update([
                'total_output_qty' => $data['total_output_qty'],
                'total_input_cost' => $totalInputCost,
                'avg_unit_cost'    => round($avgCost, 4),
                'output_ratio'     => $data['output_ratio'] ?? $record->output_ratio,
                'output_qty_json'  => $data['output_qty_json'] ?? $record->output_qty_json,
            ]);

            $existingIds = [];
            foreach ($data['inputs'] as $in) {
                $payload = [
                    'cyclic_id'     => $record->id,
                    'item_id'       => $in['item_id'],
                    'unit'          => $in['unit'] ?? null,
                    'cost_per_unit' => $in['cost_per_unit'],
                    'qty_json'      => $in['qty_json'],
                    'total_qty'     => $in['total_qty'],
                    'line_total'    => $in['line_total'],
                ];

                if (!empty($in['id'])) {
                    CyclicManufacturingInput::where('id', $in['id'])->update($payload);
                    $existingIds[] = $in['id'];
                } else {
                    $ci = CyclicManufacturingInput::create(array_merge($payload, [
                        'id' => (string) Str::orderedUuid(),
                    ]));
                    $existingIds[] = $ci->id;
                }
            }

            CyclicManufacturingInput::where('cyclic_id', $record->id)
                ->whereNotIn('id', $existingIds)
                ->delete();
        });

        $record->load('inputs.item:id,name,unit');
        return response()->json($record);
    }

    public function destroy(string $id): JsonResponse
    {
        CyclicManufacturing::findOrFail($id)->delete();
        return response()->json(['message' => 'تم الحذف']);
    }

    public function updatePrice(string $id): JsonResponse
    {
        $record = CyclicManufacturing::findOrFail($id);
        if ($record->avg_unit_cost <= 0) {
            return response()->json(['message' => 'متوسط السعر صفر، لا يمكن التحديث'], 400);
        }

        $oldCost = (float) Item::where('id', $record->item_id)->value('default_cost');
        Item::where('id', $record->item_id)->update(['default_cost' => $record->avg_unit_cost]);

        return response()->json([
            'message' => 'تم تحديث سعر ' . ($record->item?->name ?? 'الصنف') . ' من ' . number_format($oldCost, 2) . ' إلى ' . number_format($record->avg_unit_cost, 2),
            'old_cost' => $oldCost,
            'new_cost' => $record->avg_unit_cost,
        ]);
    }

    public function postToProduction(string $id): JsonResponse
    {
        $record = CyclicManufacturing::with('inputs')->findOrFail($id);
        $clientId = $record->client_id;

        // استخدام output_qty_json لو موجود، وإلا التوزيع حسب المدخلات
        if ($record->output_qty_json) {
            $dayQuantities = [];
            foreach ($record->output_qty_json as $day => $qty) {
                $q = (float) $qty;
                if ($q > 0) {
                    $dayQuantities[(int) $day] = $q;
                }
            }
        } else {
            $dayQuantities = [];
            foreach ($record->inputs as $inp) {
                $qtyJson = $inp->qty_json ?? [];
                foreach ($qtyJson as $day => $qty) {
                    $q = (float) $qty;
                    if ($q > 0) {
                        $dayQuantities[(int) $day] = ($dayQuantities[(int) $day] ?? 0) + $q;
                    }
                }
            }

            $totalInputSum = array_sum($dayQuantities);
            if ($totalInputSum > 0) {
                foreach ($dayQuantities as $day => $inputQty) {
                    $dayQuantities[$day] = $record->total_output_qty * ($inputQty / $totalInputSum);
                }
            }
        }

        if (empty($dayQuantities)) {
            return response()->json(['message' => 'لا توجد كميات في الأيام للترحيل'], 400);
        }

        $posted = 0;
        $yearMonth = explode('-', $record->month);
        $year = $yearMonth[0];
        $monthNum = $yearMonth[1];

        foreach ($dayQuantities as $day => $outputQty) {
            if ($outputQty <= 0) continue;
            $date = sprintf('%s-%s-%02d', $year, $monthNum, $day);

            DailyProduction::updateOrCreate(
                [
                    'client_id'  => $clientId,
                    'recipe_id'  => $record->item_id,
                    'size_index' => null,
                    'date'       => $date,
                ],
                ['qty' => DB::raw('qty + ' . $outputQty)]
            );
            $posted++;
        }

        $record->update(['posted_to_production' => true]);

        return response()->json([
            'message' => 'تم ترحيل ' . $posted . ' يوم للإنتاج اليومي',
            'posted'  => $posted,
        ]);
    }
}
