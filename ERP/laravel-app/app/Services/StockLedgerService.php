<?php

namespace App\Services;

use App\Models\StockLedger;
use Illuminate\Support\Facades\DB;

/**
 * StockLedgerService
 * المسؤول عن تسجيل كل حركة في الـ ledger
 * وعكس الحركة لو اتحذف إذن
 */
class StockLedgerService
{
    /**
     * سجّل حركة في الـ ledger
     */
    public function post(
        string $clientId,
        string $whId,
        string $itemId,
        string $date,
        string $movementType, // in | out | transfer_in | transfer_out
        float  $qty,
        float  $totalCost,
        float  $unitCost,
        string $refType,
        string $refId,
        string $voucherType
    ): StockLedger {
        return StockLedger::create([
            'client_id'     => $clientId,
            'warehouse_id'  => $whId,
            'item_id'       => $itemId,
            'date'          => $date,
            'movement_type' => $movementType,
            'voucher_type'  => $voucherType,
            'qty'           => abs($qty),
            'unit_cost'     => $unitCost,
            'total_cost'    => $totalCost,
            'ref_type'      => $refType,
            'ref_id'        => $refId,
        ]);
    }

    /**
     * تحويل بين مخزنين — قيدين في الـ ledger
     */
    public function postTransfer(
        string $clientId,
        string $fromWarehouseId,
        string $toWarehouseId,
        string $itemId,
        string $date,
        float  $qty,
        float  $unitCost,
        string $refId,
    ): array {
        return DB::transaction(function () use (
            $clientId, $fromWarehouseId, $toWarehouseId,
            $itemId, $date, $qty, $unitCost, $refId
        ) {
            $cost = round($qty * $unitCost, 2);

            $out = StockLedger::create([
                'client_id'     => $clientId,
                'warehouse_id'  => $fromWarehouseId,
                'item_id'       => $itemId,
                'date'          => $date,
                'movement_type' => 'transfer_out',
                'voucher_type'  => 'dispatch',
                'qty'           => $qty,
                'unit_cost'     => $unitCost,
                'total_cost'    => $cost,
                'ref_type'      => 'dispatch_order',
                'ref_id'        => $refId,
            ]);

            $in = StockLedger::create([
                'client_id'     => $clientId,
                'warehouse_id'  => $toWarehouseId,
                'item_id'       => $itemId,
                'date'          => $date,
                'movement_type' => 'transfer_in',
                'voucher_type'  => 'dispatch',
                'qty'           => $qty,
                'unit_cost'     => $unitCost,
                'total_cost'    => $cost,
                'ref_type'      => 'dispatch_order',
                'ref_id'        => $refId,
            ]);

            return [$out, $in];
        });
    }

    /**
     * عكس حركات إذن كامل (للحذف)
     * بيحذف كل سطور الـ ledger المرتبطة بالإذن
     */
    public function reverseOrder(string $refId): int
    {
        return StockLedger::where('ref_type', 'dispatch_order')
            ->where('ref_id', $refId)
            ->delete();
    }

    /**
     * رصيد صنف في مخزن بتاريخ معين
     */
    public function balance(
        string $clientId,
        string $warehouseId,
        string $itemId,
        ?string $asOfDate = null
    ): float {
        $q = StockLedger::where('client_id', $clientId)
            ->where('warehouse_id', $warehouseId)
            ->where('item_id', $itemId);

        if ($asOfDate) $q->where('date', '<=', $asOfDate);

        $result = $q->selectRaw("
            SUM(CASE WHEN movement_type IN ('in','transfer_in')  THEN qty ELSE 0 END) -
            SUM(CASE WHEN movement_type IN ('out','transfer_out') THEN qty ELSE 0 END) AS balance
        ")->value('balance');

        return round((float) $result, 3);
    }

    /**
     * ملخص حركة مخزن كامل — لصفحة "وارد مخزن"
     */
    public function warehouseSummary(
        string $clientId,
        string $warehouseId,
        string $month
    ): array {
        [$start, $end] = $this->monthRange($month);

        return StockLedger::where('client_id', $clientId)
            ->where('warehouse_id', $warehouseId)
            ->whereBetween('date', [$start, $end])
            ->join('items', 'items.id', '=', 'stock_ledger.item_id')
            ->selectRaw("
                items.id   AS item_id,
                items.name AS item_name,
                items.unit,
                SUM(CASE WHEN movement_type IN ('in','transfer_in')
                    THEN qty ELSE 0 END)                        AS in_qty,
                SUM(CASE WHEN movement_type IN ('in','transfer_in')
                    THEN total_cost ELSE 0 END)                 AS in_value,
                SUM(CASE WHEN movement_type IN ('out','transfer_out')
                    THEN qty ELSE 0 END)                        AS out_qty,
                SUM(CASE WHEN movement_type IN ('in','transfer_in') AND qty > 0
                    THEN total_cost ELSE 0 END) /
                NULLIF(SUM(CASE WHEN movement_type IN ('in','transfer_in')
                    THEN qty ELSE 0 END), 0)                    AS avg_cost
            ")
            ->groupBy('items.id', 'items.name', 'items.unit')
            ->get()
            ->toArray();
    }

    /**
     * حركة صنف يوم بيوم في شهر معين
     */
    public function dailyMovement(
        string $clientId,
        string $warehouseId,
        string $itemId,
        string $month
    ): array {
        [$start, $end] = $this->monthRange($month);

        return StockLedger::where('client_id', $clientId)
            ->where('warehouse_id', $warehouseId)
            ->where('item_id', $itemId)
            ->whereBetween('date', [$start, $end])
            ->selectRaw("date, movement_type, SUM(qty) as qty, SUM(total_cost) as cost")
            ->groupBy('date', 'movement_type')
            ->orderBy('date')
            ->get()
            ->toArray();
    }

    private function monthRange(string $month): array
    {
        $start = \Carbon\Carbon::parse($month . '-01');
        return [$start->toDateString(), $start->endOfMonth()->toDateString()];
    }
}
