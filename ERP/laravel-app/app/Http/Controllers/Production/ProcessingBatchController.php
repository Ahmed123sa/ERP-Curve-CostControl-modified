<?php
namespace App\Http\Controllers\Production;

use App\Http\Controllers\Controller;
use App\Models\Production\ProcessingBatch;
use App\Models\Production\ProcessingBatchDay;
use App\Models\Production\ProcessingBatchInput;
use App\Models\Production\ProcessingBatchOutput;
use App\Models\Production\DailyProduction;
use App\Models\Production\Recipe;
use App\Models\Item;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class ProcessingBatchController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $batches = ProcessingBatch::where('client_id', $request->user()->current_client_id)
            ->with('days')
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($b) {
                $dates = $b->days->map(fn($d) => $d->date->toDateString())->values();
                $totalQty = ProcessingBatchOutput::whereIn('batch_day_id', $b->days->pluck('id'))->sum('qty');
                return [
                    'id'               => $b->id,
                    'name'             => $b->name,
                    'dates'            => $dates,
                    'dates_count'      => $dates->count(),
                    'total_output_qty' => round((float) $totalQty, 2),
                    'notes'            => $b->notes,
                ];
            });

        return response()->json($batches);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'               => 'required|string|max:255',
            'notes'              => 'nullable|string',
            'days'               => 'required|array|min:1',
            'days.*.date'        => 'required|date',
            'days.*.processes'   => 'nullable|array',
            'days.*.processes.*.name'       => 'required|string',
            'days.*.processes.*.net_weight' => 'nullable|numeric|min:0',
            'days.*.notes'       => 'nullable|string',
            'days.*.inputs'      => 'required|array|min:1',
            'days.*.inputs.*.item_id'      => 'required|string',
            'days.*.inputs.*.qty'          => 'required|numeric|min:0',
            'days.*.inputs.*.cost_per_kg'  => 'required|numeric|min:0',
            'days.*.outputs'     => 'required|array|min:1',
            'days.*.outputs.*.item_id'     => 'required|string',
            'days.*.outputs.*.qty'         => 'required|numeric|min:0',
        ]);

        $clientId = $request->user()->current_client_id;

        $batch = DB::transaction(function () use ($clientId, $data) {
            $batch = ProcessingBatch::create([
                'id'        => (string) Str::orderedUuid(),
                'client_id' => $clientId,
                'name'      => $data['name'],
                'notes'     => $data['notes'] ?? null,
            ]);

            foreach ($data['days'] as $idx => $dayData) {
                $this->createDay($batch, $dayData, $idx);
            }

            return $batch->load(['days.inputs.item:id,name,unit', 'days.outputs.item:id,name,unit']);
        });

        return response()->json($batch, 201);
    }

    public function show(string $id): JsonResponse
    {
        $batch = ProcessingBatch::with([
            'days' => fn($q) => $q->orderBy('sort_order')->orderBy('date'),
            'days.inputs.item:id,name,unit',
            'days.outputs.item:id,name,unit',
        ])->findOrFail($id);

        return response()->json($batch);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $batch = ProcessingBatch::with('days.inputs', 'days.outputs')->findOrFail($id);

        $data = $request->validate([
            'name'               => 'required|string|max:255',
            'notes'              => 'nullable|string',
            'days'               => 'required|array|min:1',
            'days.*.id'          => 'nullable|string',
            'days.*.date'        => 'required|date',
            'days.*.processes'   => 'nullable|array',
            'days.*.processes.*.name'       => 'required|string',
            'days.*.processes.*.net_weight' => 'nullable|numeric|min:0',
            'days.*.notes'       => 'nullable|string',
            'days.*.inputs'      => 'required|array|min:1',
            'days.*.inputs.*.item_id'      => 'required|string',
            'days.*.inputs.*.qty'          => 'required|numeric|min:0',
            'days.*.inputs.*.cost_per_kg'  => 'required|numeric|min:0',
            'days.*.outputs'     => 'required|array|min:1',
            'days.*.outputs.*.item_id'     => 'required|string',
            'days.*.outputs.*.qty'         => 'required|numeric|min:0',
        ]);

        DB::transaction(function () use ($batch, $data) {
            $batch->update([
                'name'  => $data['name'],
                'notes' => $data['notes'] ?? null,
            ]);

            $incomingIds = collect($data['days'])->pluck('id')->filter()->values();
            $batch->days()->whereNotIn('id', $incomingIds)->each(function ($day) {
                $day->inputs()->delete();
                $day->outputs()->delete();
                $day->delete();
            });

            foreach ($data['days'] as $idx => $dayData) {
                if (!empty($dayData['id'])) {
                    $day = ProcessingBatchDay::find($dayData['id']);
                    if ($day && $day->batch_id === $batch->id) {
                        $day->update([
                            'date'       => $dayData['date'],
                            'processes'  => $dayData['processes'] ?? null,
                            'notes'      => $dayData['notes'] ?? null,
                            'sort_order' => $idx,
                        ]);
                        $day->inputs()->delete();
                        $day->outputs()->delete();
                        $this->createDayInputsOutputs($day, $dayData);
                        continue;
                    }
                }
                $this->createDay($batch, $dayData, $idx);
            }
        });

        $batch->load(['days.inputs.item:id,name,unit', 'days.outputs.item:id,name,unit']);
        return response()->json($batch);
    }

    public function destroy(string $id): JsonResponse
    {
        $batch = ProcessingBatch::findOrFail($id);
        $batch->delete();
        return response()->json(['message' => 'تم حذف المعالجة']);
    }

    public function summary(Request $request): JsonResponse
    {
        $clientId = $request->user()->current_client_id;
        $month = $request->query('month', now()->format('Y-m'));
        $start = Carbon::parse($month . '-01');
        $end = $start->copy()->endOfMonth();

        $dayIds = ProcessingBatchDay::whereHas('batch', fn($q) => $q->where('client_id', $clientId))
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->pluck('id');

        $inputs = ProcessingBatchInput::whereIn('batch_day_id', $dayIds)
            ->select('item_id', DB::raw('SUM(qty) as total_qty'), DB::raw('SUM(line_total) as total_cost'))
            ->with('item:id,name,unit')
            ->groupBy('item_id')
            ->get()
            ->map(fn($i) => [
                'item_id'    => $i->item_id,
                'name'       => $i->item?->name ?? '',
                'unit'       => $i->item?->unit ?? '',
                'total_qty'  => (float) $i->total_qty,
                'total_cost' => (float) $i->total_cost,
                'avg_cost_per_kg' => (float) $i->total_qty > 0 ? (float) $i->total_cost / (float) $i->total_qty : 0,
            ]);

        $outputs = ProcessingBatchOutput::whereIn('batch_day_id', $dayIds)
            ->select('item_id', DB::raw('SUM(qty) as total_qty'), DB::raw('SUM(total_cost) as total_cost'))
            ->with('item:id,name,unit')
            ->groupBy('item_id')
            ->get()
            ->map(fn($o) => [
                'item_id'    => $o->item_id,
                'name'       => $o->item?->name ?? '',
                'unit'       => $o->item?->unit ?? '',
                'total_qty'  => (float) $o->total_qty,
                'total_cost' => (float) $o->total_cost,
                'avg_cost_per_kg' => (float) $o->total_qty > 0 ? (float) $o->total_cost / (float) $o->total_qty : 0,
            ]);

        return response()->json([
            'month'   => $month,
            'inputs'  => $inputs,
            'outputs' => $outputs,
            'totals'  => [
                'total_input_qty'   => (float) $inputs->sum('total_qty'),
                'total_input_cost'  => (float) $inputs->sum('total_cost'),
                'total_output_qty'  => (float) $outputs->sum('total_qty'),
                'total_output_cost' => (float) $outputs->sum('total_cost'),
            ],
        ]);
    }

    public function exportSummary(Request $request): \Illuminate\Http\Response
    {
        $clientId = $request->user()->current_client_id;
        $month = $request->query('month', now()->format('Y-m'));
        $start = Carbon::parse($month . '-01');
        $end = $start->copy()->endOfMonth();

        $dayIds = ProcessingBatchDay::whereHas('batch', fn($q) => $q->where('client_id', $clientId))
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->pluck('id');

        $inputs = ProcessingBatchInput::whereIn('batch_day_id', $dayIds)
            ->select('item_id', DB::raw('SUM(qty) as total_qty'), DB::raw('SUM(line_total) as total_cost'))
            ->with('item:id,name,unit')
            ->groupBy('item_id')
            ->get();

        $outputs = ProcessingBatchOutput::whereIn('batch_day_id', $dayIds)
            ->select('item_id', DB::raw('SUM(qty) as total_qty'), DB::raw('SUM(total_cost) as total_cost'))
            ->with('item:id,name,unit')
            ->groupBy('item_id')
            ->get();

        $spreadsheet = new Spreadsheet();

        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('المدخلات');
        $sheet->setRightToLeft(true);
        $sheet->setCellValue('A1', 'الصنف');
        $sheet->setCellValue('B1', 'الوحدة');
        $sheet->setCellValue('C1', 'إجمالي الكمية');
        $sheet->setCellValue('D1', 'متوسط سعر الكيلو');
        $sheet->setCellValue('E1', 'إجمالي التكلفة');

        $row = 2;
        foreach ($inputs as $i) {
            $qty = (float) $i->total_qty;
            $cost = (float) $i->total_cost;
            $sheet->setCellValue('A' . $row, $i->item?->name ?? '');
            $sheet->setCellValue('B' . $row, $i->item?->unit ?? '');
            $sheet->setCellValue('C' . $row, $qty);
            $sheet->setCellValue('D' . $row, $qty > 0 ? round($cost / $qty, 4) : 0);
            $sheet->setCellValue('E' . $row, $cost);
            $row++;
        }

        $sheet2 = $spreadsheet->createSheet();
        $sheet2->setTitle('المخرجات');
        $sheet2->setRightToLeft(true);
        $sheet2->setCellValue('A1', 'الصنف');
        $sheet2->setCellValue('B1', 'الوحدة');
        $sheet2->setCellValue('C1', 'إجمالي الكمية');
        $sheet2->setCellValue('D1', 'متوسط سعر الكيلو');
        $sheet2->setCellValue('E1', 'إجمالي التكلفة');

        $row = 2;
        foreach ($outputs as $o) {
            $qty = (float) $o->total_qty;
            $cost = (float) $o->total_cost;
            $sheet2->setCellValue('A' . $row, $o->item?->name ?? '');
            $sheet2->setCellValue('B' . $row, $o->item?->unit ?? '');
            $sheet2->setCellValue('C' . $row, $qty);
            $sheet2->setCellValue('D' . $row, $qty > 0 ? round($cost / $qty, 4) : 0);
            $sheet2->setCellValue('E' . $row, $cost);
            $row++;
        }

        $writer = new Xlsx($spreadsheet);
        $filename = sprintf('ملخص_معالجة_%s.xlsx', $month);

        ob_start();
        $writer->save('php://output');
        $content = ob_get_clean();

        return response($content, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    public function postToDaily(Request $request): JsonResponse
    {
        $data = $request->validate([
            'month'    => 'required|date_format:Y-m',
            'entries'  => 'required|array|min:1',
            'entries.*.item_id'   => 'required|string',
            'entries.*.qty'       => 'required|numeric|min:0',
            'entries.*.recipe_id' => 'required|string',
            'entries.*.day'       => 'required|integer|between:1,31',
        ]);

        $clientId = $request->user()->current_client_id;
        [$year, $monthNum] = explode('-', $data['month']);
        $created = 0;

        foreach ($data['entries'] as $entry) {
            $date = sprintf('%s-%s-%02d', $year, $monthNum, (int) $entry['day']);
            $recipeId = $entry['recipe_id'];
            $sizeIndex = null;
            if (str_contains($recipeId, '::size::')) {
                $parts = explode('::size::', $recipeId);
                $recipeId = $parts[0];
                $sizeIndex = (int) $parts[1];
            }

            DailyProduction::updateOrCreate(
                [
                    'client_id'  => $clientId,
                    'recipe_id'  => $recipeId,
                    'size_index' => $sizeIndex,
                    'date'       => $date,
                ],
                [
                    'qty' => DB::raw('qty + ' . round((float) $entry['qty'], 4)),
                ]
            );
            $created++;
        }

        return response()->json([
            'message' => sprintf('تم تحويل %d صنف للإنتاج اليومي', $created),
        ]);
    }

    public function syncOutputCosts(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'item_ids' => 'required|array',
            'item_ids.*' => 'required|string',
        ]);

        $batch = ProcessingBatch::with('days.outputs')->findOrFail($id);
        $updated = [];

        $allOutputs = $batch->days->flatMap->outputs;

        foreach ($allOutputs as $output) {
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

    private function createDay(ProcessingBatch $batch, array $dayData, int $sortOrder): ProcessingBatchDay
    {
        $day = ProcessingBatchDay::create([
            'id'         => (string) Str::orderedUuid(),
            'client_id'  => $batch->client_id,
            'batch_id'   => $batch->id,
            'date'       => $dayData['date'],
            'processes'  => $dayData['processes'] ?? null,
            'notes'      => $dayData['notes'] ?? null,
            'sort_order' => $sortOrder,
        ]);

        $this->createDayInputsOutputs($day, $dayData);
        return $day;
    }

    private function createDayInputsOutputs(ProcessingBatchDay $day, array $dayData): void
    {
        $totalInputCost = 0;
        foreach ($dayData['inputs'] as $in) {
            $lineTotal = (float) $in['qty'] * (float) $in['cost_per_kg'];
            $totalInputCost += $lineTotal;
            ProcessingBatchInput::create([
                'id'          => (string) Str::orderedUuid(),
                'batch_id'    => $day->batch_id,
                'batch_day_id' => $day->id,
                'item_id'     => $in['item_id'],
                'qty'         => $in['qty'],
                'cost_per_kg' => $in['cost_per_kg'],
                'line_total'  => $lineTotal,
            ]);
        }

        $grandTotal = collect($dayData['outputs'])->sum(fn($o) => (float) $o['qty']);

        foreach ($dayData['outputs'] as $out) {
            $qty = (float) $out['qty'];
            $allocPct = $grandTotal > 0 ? $qty / $grandTotal : 0;
            $outTotalCost = $totalInputCost * $allocPct;
            $effCostPerKg = $qty > 0 ? $outTotalCost / $qty : 0;

            ProcessingBatchOutput::create([
                'id'                   => (string) Str::orderedUuid(),
                'batch_id'             => $day->batch_id,
                'batch_day_id'         => $day->id,
                'item_id'              => $out['item_id'],
                'qty'                  => $qty,
                'effective_cost_per_kg' => round($effCostPerKg, 4),
                'total_cost'           => round($outTotalCost, 4),
                'allocation_pct'       => round($allocPct * 100, 2),
            ]);
        }
    }
}
