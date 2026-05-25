<?php
namespace App\Http\Controllers\Production;

use App\Http\Controllers\Controller;
use App\Models\Production\MarketItem;
use App\Models\Production\MarketPrice;
use App\Services\MarketScraper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MarketPriceController extends Controller
{
    public function scrape(): JsonResponse
    {
        $scraper = new MarketScraper();
        $data = $scraper->scrape();
        return response()->json($data);
    }

    public function index(Request $request): JsonResponse
    {
        $clientId = $request->user()->current_client_id;
        $date = $request->date ?? now()->toDateString();

        $items = MarketItem::where('client_id', $clientId)
            ->orderBy('sort_order')
            ->get();

        $result = $items->map(function ($mi) use ($clientId, $date) {
            $latestPrice = MarketPrice::where('client_id', $clientId)
                ->where('item_name', $mi->item_name)
                ->where('date', $date)
                ->first();

            $lastUpdate = MarketPrice::where('client_id', $clientId)
                ->where('item_name', $mi->item_name)
                ->orderBy('date', 'desc')
                ->first();

            return [
                'id'         => $mi->id,
                'item_name'  => $mi->item_name,
                'unit'       => $mi->unit ?? '',
                'price'      => $latestPrice ? (float) $latestPrice->price : null,
                'last_date'  => $lastUpdate?->date?->toDateString(),
                'last_price' => $lastUpdate ? (float) $lastUpdate->price : null,
            ];
        });

        return response()->json($result);
    }

    public function items(Request $request): JsonResponse
    {
        $clientId = $request->user()->current_client_id;
        $trackedNames = MarketItem::where('client_id', $clientId)->pluck('item_name');

        $scraper = new MarketScraper();
        $all = $scraper->scrape();

        $available = [];
        foreach ($all as $section => $items) {
            foreach ($items as $item) {
                if ($trackedNames->contains($item['name'])) continue;
                $available[] = [
                    'name'    => $item['name'],
                    'unit'    => $item['unit'] ?? 'كجم',
                    'section' => $section,
                    'price'   => $item['price'] ?? null,
                ];
            }
        }

        return response()->json($available);
    }

    public function addItem(Request $request): JsonResponse
    {
        $data = $request->validate([
            'item_name' => 'required|string|max:255',
            'unit'      => 'nullable|string|max:50',
        ]);
        $clientId = $request->user()->current_client_id;

        $exists = MarketItem::where('client_id', $clientId)
            ->where('item_name', $data['item_name'])->exists();

        if ($exists) {
            return response()->json(['message' => 'الصنف موجود بالفعل'], 409);
        }

        $maxSort = MarketItem::where('client_id', $clientId)->max('sort_order') ?? 0;
        $item = MarketItem::create([
            'client_id'   => $clientId,
            'item_name'   => $data['item_name'],
            'unit'        => $data['unit'] ?? null,
            'sort_order'  => $maxSort + 1,
        ]);

        return response()->json($item, 201);
    }

    public function removeItem(string $id): JsonResponse
    {
        $item = MarketItem::findOrFail($id);
        $item->delete();
        return response()->json(['message' => 'removed']);
    }

    public function updatePrices(Request $request): JsonResponse
    {
        $data = $request->validate([
            'date'   => 'required|date',
            'prices' => 'required|array',
            'prices.*.item_name' => 'required|string',
            'prices.*.price'    => 'nullable|numeric|min:0',
        ]);
        $clientId = $request->user()->current_client_id;
        $date = $data['date'];

        DB::transaction(function () use ($data, $clientId, $date) {
            foreach ($data['prices'] as $entry) {
                if ($entry['price'] === null || $entry['price'] === '') continue;

                MarketPrice::updateOrCreate(
                    [
                        'client_id' => $clientId,
                        'item_name' => $entry['item_name'],
                        'date'      => $date,
                    ],
                    ['price' => $entry['price']]
                );
            }
        });

        return response()->json(['message' => 'تم حفظ الأسعار']);
    }

    public function latest(Request $request): JsonResponse
    {
        $clientId = $request->user()->current_client_id;

        $items = MarketItem::where('client_id', $clientId)
            ->orderBy('sort_order')
            ->get();

        $result = $items->map(function ($mi) use ($clientId) {
            $latest = MarketPrice::where('client_id', $clientId)
                ->where('item_name', $mi->item_name)
                ->orderBy('date', 'desc')
                ->first();

            return [
                'item_name' => $mi->item_name,
                'unit'      => $mi->unit ?? '',
                'price'     => $latest ? (float) $latest->price : null,
                'date'      => $latest?->date?->toDateString(),
            ];
        });

        return response()->json($result);
    }
}
