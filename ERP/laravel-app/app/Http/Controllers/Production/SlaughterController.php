<?php
namespace App\Http\Controllers\Production;

use App\Http\Controllers\Controller;
use App\Models\Production\Slaughter;
use App\Models\Production\SlaughterItem;
use App\Models\Production\Recipe;
use App\Models\Production\DailyProduction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SlaughterController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $clientId = $request->user()->current_client_id;

        $slaughters = Slaughter::where('client_id', $clientId)
            ->orderBy('date', 'desc')
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($s) {
                return [
                    'id'                       => $s->id,
                    'date'                     => $s->date->toDateString(),
                    'animal_name'              => $s->animal_name ?? '',
                    'live_weight'              => (float) $s->live_weight,
                    'price_per_kg'             => (float) $s->price_per_kg,
                    'transport_slaughter_cost' => (float) $s->transport_slaughter_cost,
                    'total_cost'               => (float) $s->total_cost,
                    'items_count'              => $s->items()->count(),
                    'notes'                    => $s->notes,
                ];
            });

        return response()->json($slaughters);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'date'                     => 'required|date',
            'animal_name'              => 'required|string|max:255',
            'live_weight'              => 'required|numeric|min:0',
            'price_per_kg'             => 'required|numeric|min:0',
            'transport_slaughter_cost' => 'nullable|numeric|min:0',
            'notes'                    => 'nullable|string',
            'items'                    => 'required|array|min:1',
            'items.*.item_id'          => 'required|string',
            'items.*.warehouse_id'     => 'nullable|string',
            'items.*.unit'             => 'nullable|string|max:50',
            'items.*.weight'           => 'required|numeric|min:0',
            'items.*.selling_price'    => 'nullable|numeric|min:0',
            'items.*.sort_order'       => 'nullable|integer',
        ]);

        $clientId = $request->user()->current_client_id;
        $transport = (float) ($data['transport_slaughter_cost'] ?? 0);
        $liveCost  = (float) $data['live_weight'] * (float) $data['price_per_kg'];
        $totalCost = $liveCost + $transport;

        $items = $data['items'];
        $grandTotal = 0;
        foreach ($items as &$item) {
            $w   = (float) $item['weight'];
            $sp  = (float) ($item['selling_price'] ?? 0);
            $item['total'] = $w * $sp;
            $grandTotal += $item['total'];
            $item['sort_order'] = $item['sort_order'] ?? 0;
        }
        unset($item);

        $slaughter = DB::transaction(function () use ($clientId, $data, $totalCost, $items, $grandTotal) {
            $slaughter = Slaughter::create([
                'client_id'               => $clientId,
                'date'                    => $data['date'],
                'animal_name'             => $data['animal_name'],
                'live_weight'             => $data['live_weight'],
                'price_per_kg'            => $data['price_per_kg'],
                'transport_slaughter_cost' => $data['transport_slaughter_cost'] ?? 0,
                'total_cost'              => $totalCost,
                'notes'                   => $data['notes'] ?? null,
            ]);

            foreach ($items as $item) {
                $allocPct = $grandTotal > 0 ? $item['total'] / $grandTotal : 0;
                $actualCost = $allocPct > 0 && $item['weight'] > 0
                    ? ($totalCost * $allocPct) / $item['weight']
                    : 0;

                SlaughterItem::create([
                    'id'                => (string) Str::orderedUuid(),
                    'slaughter_id'      => $slaughter->id,
                    'item_id'           => $item['item_id'],
                    'warehouse_id'      => $item['warehouse_id'] ?? null,
                    'unit'              => $item['unit'] ?? null,
                    'weight'            => $item['weight'],
                    'selling_price'     => $item['selling_price'] ?? 0,
                    'total'             => $item['total'],
                    'allocation_pct'    => round($allocPct * 100, 2),
                    'actual_cost_per_kg' => round($actualCost, 4),
                    'sort_order'        => $item['sort_order'],
                ]);
            }

            return $slaughter->load('items.item:id,name,unit', 'items.warehouse:id,name');
        });

        return response()->json($slaughter, 201);
    }

    public function show(string $id): JsonResponse
    {
        $slaughter = Slaughter::with('items.item:id,name,unit', 'items.warehouse:id,name')->findOrFail($id);
        return response()->json($slaughter);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $slaughter = Slaughter::findOrFail($id);

        $data = $request->validate([
            'date'                     => 'required|date',
            'animal_name'              => 'required|string|max:255',
            'live_weight'              => 'required|numeric|min:0',
            'price_per_kg'             => 'required|numeric|min:0',
            'transport_slaughter_cost' => 'nullable|numeric|min:0',
            'notes'                    => 'nullable|string',
            'items'                    => 'required|array|min:1',
            'items.*.id'               => 'nullable|string',
            'items.*.item_id'          => 'required|string',
            'items.*.warehouse_id'     => 'nullable|string',
            'items.*.unit'             => 'nullable|string|max:50',
            'items.*.weight'           => 'required|numeric|min:0',
            'items.*.selling_price'    => 'nullable|numeric|min:0',
            'items.*.sort_order'       => 'nullable|integer',
        ]);

        $transport = (float) ($data['transport_slaughter_cost'] ?? 0);
        $liveCost  = (float) $data['live_weight'] * (float) $data['price_per_kg'];
        $totalCost = $liveCost + $transport;

        $items = $data['items'];
        $grandTotal = 0;
        foreach ($items as &$item) {
            $w   = (float) $item['weight'];
            $sp  = (float) ($item['selling_price'] ?? 0);
            $item['total'] = $w * $sp;
            $grandTotal += $item['total'];
            $item['sort_order'] = $item['sort_order'] ?? 0;
        }
        unset($item);

        DB::transaction(function () use ($slaughter, $data, $totalCost, $items, $grandTotal) {
            $slaughter->update([
                'date'                    => $data['date'],
                'animal_name'             => $data['animal_name'],
                'live_weight'             => $data['live_weight'],
                'price_per_kg'            => $data['price_per_kg'],
                'transport_slaughter_cost' => $data['transport_slaughter_cost'] ?? 0,
                'total_cost'              => $totalCost,
                'notes'                   => $data['notes'] ?? null,
            ]);

            $existingIds = [];
            foreach ($items as $item) {
                $allocPct = $grandTotal > 0 ? $item['total'] / $grandTotal : 0;
                $actualCost = $allocPct > 0 && $item['weight'] > 0
                    ? ($totalCost * $allocPct) / $item['weight']
                    : 0;

                $payload = [
                    'slaughter_id'      => $slaughter->id,
                    'item_id'           => $item['item_id'],
                    'warehouse_id'      => $item['warehouse_id'] ?? null,
                    'unit'              => $item['unit'] ?? null,
                    'weight'            => $item['weight'],
                    'selling_price'     => $item['selling_price'] ?? 0,
                    'total'             => $item['total'],
                    'allocation_pct'    => round($allocPct * 100, 2),
                    'actual_cost_per_kg' => round($actualCost, 4),
                    'sort_order'        => $item['sort_order'],
                ];

                if (!empty($item['id'])) {
                    SlaughterItem::where('id', $item['id'])->update($payload);
                    $existingIds[] = $item['id'];
                } else {
                    $si = SlaughterItem::create(array_merge($payload, [
                        'id' => (string) Str::orderedUuid(),
                    ]));
                    $existingIds[] = $si->id;
                }
            }

            SlaughterItem::where('slaughter_id', $slaughter->id)
                ->whereNotIn('id', $existingIds)
                ->delete();
        });

        $slaughter->load('items.item:id,name,unit', 'items.warehouse:id,name');
        return response()->json($slaughter);
    }

    public function destroy(string $id): JsonResponse
    {
        $slaughter = Slaughter::findOrFail($id);
        $slaughter->delete();
        return response()->json(['message' => 'تم حذف التصفية']);
    }

    public function postToProduction(string $id): JsonResponse
    {
        $slaughter = Slaughter::with('items.item:id,name,unit')->findOrFail($id);
        $clientId = $slaughter->client_id;
        $date = $slaughter->date->toDateString();

        $posted = 0;
        $skipped = [];

        foreach ($slaughter->items as $si) {
            $recipe = Recipe::where('client_id', $clientId)
                ->where('item_id', $si->item_id)
                ->first();

            if (!$recipe) {
                $skipped[] = $si->item?->name ?? $si->item_id;
                continue;
            }

            DailyProduction::updateOrCreate(
                [
                    'client_id'  => $clientId,
                    'recipe_id'  => $recipe->id,
                    'date'       => $date,
                    'size_index' => null,
                ],
                [
                    'qty' => $si->weight,
                ]
            );
            $posted++;
        }

        return response()->json([
            'message' => 'تم ترحيل ' . $posted . ' صنف للإنتاج اليومي' . (count($skipped) ? '، تخطي: ' . implode('، ', $skipped) : ''),
            'posted'  => $posted,
            'skipped' => $skipped,
        ]);
    }
}
