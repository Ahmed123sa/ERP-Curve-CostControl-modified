<?php
namespace App\Http\Controllers\Production;

use App\Http\Controllers\Controller;
use App\Models\Production\ProcessingBatch;
use App\Models\Production\ProcessingBatchInput;
use App\Models\Production\ProcessingBatchOutput;
use App\Models\Item;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProcessingBatchController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $batches = ProcessingBatch::where('client_id', $request->user()->current_client_id)
            ->orderBy('date', 'desc')
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($b) {
                return [
                    'id'               => $b->id,
                    'date'             => $b->date->toDateString(),
                    'name'             => $b->name,
                    'processes'        => $b->processes,
                    'total_input_cost' => (float) $b->total_input_cost,
                    'inputs_count'     => $b->inputs()->count(),
                    'outputs_count'    => $b->outputs()->count(),
                    'notes'            => $b->notes,
                ];
            });

        return response()->json($batches);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'date'               => 'required|date',
            'name'               => 'required|string|max:255',
            'processes'          => 'nullable|array',
            'processes.*.name'       => 'required|string',
            'processes.*.net_weight' => 'nullable|numeric|min:0',
            'notes'              => 'nullable|string',
            'inputs'             => 'required|array|min:1',
            'inputs.*.item_id'   => 'required|string',
            'inputs.*.qty'       => 'required|numeric|min:0',
            'inputs.*.cost_per_kg' => 'required|numeric|min:0',
            'outputs'            => 'required|array|min:1',
            'outputs.*.item_id'  => 'required|string',
            'outputs.*.qty'      => 'required|numeric|min:0',
        ]);

        $clientId = $request->user()->current_client_id;

        $inputs = $data['inputs'];
        $totalInputCost = 0;
        foreach ($inputs as &$in) {
            $in['line_total'] = (float) $in['qty'] * (float) $in['cost_per_kg'];
            $totalInputCost += $in['line_total'];
        }
        unset($in);

        $outputs = $data['outputs'];
        $grandTotal = 0;
        foreach ($outputs as &$out) {
            $out['total_cost'] = (float) $out['qty']; // placeholder, will be recalculated
            $grandTotal += (float) $out['qty'];
        }
        unset($out);

        $batch = DB::transaction(function () use ($clientId, $data, $totalInputCost, $inputs, $outputs, $grandTotal) {
            $batch = ProcessingBatch::create([
                'id'               => (string) Str::orderedUuid(),
                'client_id'        => $clientId,
                'date'             => $data['date'],
                'name'             => $data['name'],
                'processes'        => $data['processes'] ?? null,
                'total_input_cost' => $totalInputCost,
                'notes'            => $data['notes'] ?? null,
            ]);

            foreach ($inputs as $in) {
                ProcessingBatchInput::create([
                    'id'          => (string) Str::orderedUuid(),
                    'batch_id'    => $batch->id,
                    'item_id'     => $in['item_id'],
                    'qty'         => $in['qty'],
                    'cost_per_kg' => $in['cost_per_kg'],
                    'line_total'  => $in['line_total'],
                ]);
            }

            foreach ($outputs as $out) {
                $allocPct = $grandTotal > 0 ? ((float) $out['qty'] / $grandTotal) : 0;
                $outTotalCost = $totalInputCost * $allocPct;
                $effCostPerKg = (float) $out['qty'] > 0 ? $outTotalCost / (float) $out['qty'] : 0;

                ProcessingBatchOutput::create([
                    'id'                   => (string) Str::orderedUuid(),
                    'batch_id'             => $batch->id,
                    'item_id'              => $out['item_id'],
                    'qty'                  => $out['qty'],
                    'effective_cost_per_kg' => round($effCostPerKg, 4),
                    'total_cost'           => round($outTotalCost, 4),
                    'allocation_pct'       => round($allocPct * 100, 2),
                ]);
            }

            return $batch->load(['inputs.item:id,name,unit', 'outputs.item:id,name,unit']);
        });

        return response()->json($batch, 201);
    }

    public function show(string $id): JsonResponse
    {
        $batch = ProcessingBatch::with('inputs.item:id,name,unit', 'outputs.item:id,name,unit')->findOrFail($id);
        return response()->json($batch);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $batch = ProcessingBatch::with('inputs', 'outputs')->findOrFail($id);

        $data = $request->validate([
            'date'               => 'required|date',
            'name'               => 'required|string|max:255',
            'processes'          => 'nullable|array',
            'processes.*.name'       => 'required|string',
            'processes.*.net_weight' => 'nullable|numeric|min:0',
            'notes'              => 'nullable|string',
            'inputs'             => 'required|array|min:1',
            'inputs.*.item_id'   => 'required|string',
            'inputs.*.qty'       => 'required|numeric|min:0',
            'inputs.*.cost_per_kg' => 'required|numeric|min:0',
            'outputs'            => 'required|array|min:1',
            'outputs.*.item_id'  => 'required|string',
            'outputs.*.qty'      => 'required|numeric|min:0',
        ]);

        $inputs = $data['inputs'];
        $totalInputCost = 0;
        foreach ($inputs as &$in) {
            $in['line_total'] = (float) $in['qty'] * (float) $in['cost_per_kg'];
            $totalInputCost += $in['line_total'];
        }
        unset($in);

        $outputs = $data['outputs'];
        $grandTotal = 0;
        foreach ($outputs as &$out) {
            $out['total_cost'] = (float) $out['qty'];
            $grandTotal += (float) $out['qty'];
        }
        unset($out);

        DB::transaction(function () use ($batch, $data, $totalInputCost, $inputs, $outputs, $grandTotal) {
            $batch->update([
                'date'             => $data['date'],
                'name'             => $data['name'],
                'processes'        => $data['processes'] ?? null,
                'total_input_cost' => $totalInputCost,
                'notes'            => $data['notes'] ?? null,
            ]);

            $batch->inputs()->delete();
            $batch->outputs()->delete();

            foreach ($inputs as $in) {
                ProcessingBatchInput::create([
                    'id'          => (string) Str::orderedUuid(),
                    'batch_id'    => $batch->id,
                    'item_id'     => $in['item_id'],
                    'qty'         => $in['qty'],
                    'cost_per_kg' => $in['cost_per_kg'],
                    'line_total'  => $in['line_total'],
                ]);
            }

            foreach ($outputs as $out) {
                $allocPct = $grandTotal > 0 ? ((float) $out['qty'] / $grandTotal) : 0;
                $outTotalCost = $totalInputCost * $allocPct;
                $effCostPerKg = (float) $out['qty'] > 0 ? $outTotalCost / (float) $out['qty'] : 0;

                ProcessingBatchOutput::create([
                    'id'                   => (string) Str::orderedUuid(),
                    'batch_id'             => $batch->id,
                    'item_id'              => $out['item_id'],
                    'qty'                  => $out['qty'],
                    'effective_cost_per_kg' => round($effCostPerKg, 4),
                    'total_cost'           => round($outTotalCost, 4),
                    'allocation_pct'       => round($allocPct * 100, 2),
                ]);
            }
        });

        $batch->load(['inputs.item:id,name,unit', 'outputs.item:id,name,unit']);
        return response()->json($batch);
    }

    public function syncOutputCosts(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'item_ids' => 'required|array',
            'item_ids.*' => 'required|string',
        ]);

        $batch = ProcessingBatch::with('outputs')->findOrFail($id);
        $updated = [];

        foreach ($batch->outputs as $output) {
            if (in_array($output->item_id, $data['item_ids'])) {
                $item = Item::find($output->item_id);
                if ($item) {
                    $oldCost = $item->default_cost;
                    $item->update(['default_cost' => $output->effective_cost_per_kg]);
                    $updated[] = [
                        'item_id'  => $item->id,
                        'name'     => $item->name,
                        'old_cost' => (float) $oldCost,
                        'new_cost' => (float) $output->effective_cost_per_kg,
                    ];
                }
            }
        }

        return response()->json([
            'message' => sprintf('تم تحديث %d أصناف', count($updated)),
            'updated' => $updated,
        ]);
    }

    public function destroy(string $id): JsonResponse
    {
        $batch = ProcessingBatch::findOrFail($id);
        $batch->delete();
        return response()->json(['message' => 'تم حذف المعالجة']);
    }
}
