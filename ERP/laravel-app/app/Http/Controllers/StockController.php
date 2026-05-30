<?php

namespace App\Http\Controllers;

use App\Services\CostCalculationService;
use App\Models\Item;
use App\Models\StockLedger;
use App\Models\DispatchOrder;
use App\Models\DispatchLine;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Carbon\Carbon;

class StockController extends Controller
{
    public function __construct(private CostCalculationService $calc) {}

    /**
     * الرصيد الحالي لكل الأصناف في مخزن معين
     */
    public function current(Request $request): JsonResponse
    {
        $clientId    = $request->user()->current_client_id;
        $warehouseId = $request->warehouse_id;

        if (!$warehouseId) {
            return response()->json([]);
        }

        $items = Item::where('client_id', $clientId)->where('is_active', true)->orderBy('sort_order')->get();
        $stock = [];

        foreach ($items as $item) {
            $qty = $this->calc->currentStock($clientId, $warehouseId, $item->id);
            if ($qty != 0) {
                $avg = $this->calc->weightedAverageCost($clientId, $warehouseId, $item->id);
                $stock[] = [
                    'id'       => $item->id,
                    'name'     => $item->name,
                    'unit'     => $item->unit,
                    'qty'      => $qty,
                    'avg_cost' => $avg,
                ];
            }
        }

        return response()->json($stock);
    }

    /**
     * تتبع حركة صنف معين (Ledger)
     */
    public function movement(Request $request): JsonResponse
    {
        $clientId    = $request->user()->current_client_id;
        $warehouseId = $request->warehouse_id;
        $itemId      = $request->item_id;

        if (!$warehouseId || !$itemId) {
            return response()->json([]);
        }

        $ledger = StockLedger::where('client_id', $clientId)
            ->where('warehouse_id', $warehouseId)
            ->where('item_id', $itemId)
            ->orderBy('date', 'asc')
            ->orderBy('created_at', 'asc')
            ->get();

        $balance = 0;
        $results = $ledger->map(function ($m) use (&$balance) {
            $balance += $m->qty;
            return [
                'date'            => $m->date,
                'movement_type'   => $m->movement_type,
                'qty'             => (float) $m->qty,
                'running_balance' => round($balance, 3),
                'ref_type'        => $m->ref_type,
                'ref_id'          => $m->ref_id,
            ];
        });

        return response()->json($results);
    }

    /**
     * أرصدة افتتاحية محفوظة لموقع + شهر (للـ prefill في شاشة opening)
     */
    public function opening(Request $request): JsonResponse
    {
        $clientId    = $request->user()->current_client_id;
        $warehouseId = $request->input('warehouse_id');
        $month       = $request->input('month') ?? $request->input('date');

        if (!$warehouseId || !$month) {
            return response()->json(['data' => []]);
        }

        $startOfMonth = Carbon::parse($month . '-01')->startOfDay();
        $endOfMonth   = $startOfMonth->copy()->endOfMonth();

        $orders = DispatchOrder::where('client_id', $clientId)
            ->where('type', 'opening')
            ->whereBetween('date', [$startOfMonth->toDateString(), $endOfMonth->toDateString()])
            ->where('warehouse_id', $warehouseId)
            ->pluck('id');

        if ($orders->isEmpty()) {
            return response()->json(['data' => []]);
        }

        $lines = DispatchLine::whereIn('order_id', $orders)
            ->with('item:id,name,unit')
            ->get()
            ->groupBy('item_id')
            ->map(function ($rows) {
                $first = $rows->first();
                $qty = (float) $rows->sum('qty');
                $cost = (float) $rows->sum('total_cost');

                return [
                    'item_id'   => $first->item_id,
                    'item_name' => $first->item?->name,
                    'unit'      => $first->item?->unit,
                    'qty'       => round($qty, 3),
                    'cost'      => round($cost, 2),
                ];
            })
            ->values();

        return response()->json(['data' => $lines]);
    }

    /**
     * ملخص المخزن (إجمالي القيمة)
     */
    public function warehouseSummary(Request $request): JsonResponse
    {
        $clientId    = $request->user()->current_client_id;
        $warehouseId = $request->warehouse_id;

        $items = Item::where('client_id', $clientId)->where('is_active', true)->get();
        $totalValue = 0;
        $totalItems = 0;

        foreach ($items as $item) {
            $qty = $this->calc->currentStock($clientId, $warehouseId, $item->id);
            if ($qty != 0) {
                $avg = $this->calc->weightedAverageCost($clientId, $warehouseId, $item->id);
                $totalValue += ($qty * $avg);
                $totalItems++;
            }
        }

        return response()->json([
            'total_value' => round($totalValue, 2),
            'item_count'  => $totalItems
        ]);
    }
}
