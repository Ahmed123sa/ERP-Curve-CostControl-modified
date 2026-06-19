<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\DispatchLine;
use App\Models\Item;
use App\Models\MonthlyClosing;

class VoucherProcessingService
{
    public function __construct(
        private StockLedgerService $ledger,
        private CostCalculationService $calc,
    ) {}

    public function updateItemCostAfterPurchase(
        string $clientId,
        string $itemId,
        float $unitCost,
        ?string $warehouseId,
        string $orderId,
        string $source,
    ): void {
        if ($unitCost <= 0) return;

        $item = Item::where('id', $itemId)->where('client_id', $clientId)->first();
        if (!$item) return;

        $oldCost = $item->default_cost;
        $avgCost = $warehouseId ? $this->calc->weightedAverageCost($clientId, $warehouseId, $item->id) : 0;
        $newCost = $avgCost > 0 ? $avgCost : $unitCost;
        $item->default_cost = $newCost;
        $item->save();

        $existingLog = ActivityLog::where('entity_type', 'Item')
            ->where('entity_id', $item->id)
            ->where('action', 'price_updated')
            ->where('new_values->voucher_id', $orderId)
            ->latest()
            ->first();

        if ($existingLog) {
            $existingLog->update([
                'new_values' => [
                    'default_cost' => $newCost,
                    'avg_cost' => $avgCost,
                    'unit_cost' => $unitCost,
                    'source' => $source,
                    'voucher_id' => $orderId,
                    'corrected' => true,
                ],
            ]);
        } elseif ((float) $oldCost !== $newCost) {
            ActivityLogger::log(
                action: 'price_updated',
                entityType: 'Item',
                entityId: $item->id,
                oldValues: ['default_cost' => $oldCost],
                newValues: ['default_cost' => $newCost, 'avg_cost' => $avgCost, 'unit_cost' => $unitCost, 'source' => $source, 'voucher_id' => $orderId],
            );
        }
    }

    public function recalculateMonthlyClosing(
        string $clientId,
        string $month,
        array $itemIds,
        array $warehouseIds,
    ): void {
        $calc = $this->calc;
        foreach ($warehouseIds as $whId) {
            foreach ($itemIds as $itemId) {
                $summary = $calc->itemMonthSummary($clientId, $whId, $itemId, $month);
                if ($summary['opening_qty'] == 0 && $summary['in_qty'] == 0 && $summary['out_qty'] == 0) {
                    MonthlyClosing::where('client_id', $clientId)
                        ->where('warehouse_id', $whId)
                        ->where('item_id', $itemId)
                        ->where('month', $month)
                        ->delete();
                } else {
                    MonthlyClosing::updateOrCreate(
                        [
                            'client_id'    => $clientId,
                            'warehouse_id' => $whId,
                            'item_id'      => $itemId,
                            'month'        => $month,
                        ],
                        [
                            'opening_qty'             => $summary['opening_qty'],
                            'opening_value'           => $summary['opening_value'],
                            'purchases_qty'           => $summary['purchases_qty'],
                            'purchases_value'         => $summary['purchases_value'],
                            'internal_in_qty'         => $summary['internal_in_qty'],
                            'in_qty'                  => $summary['in_qty'],
                            'in_value'                => $summary['in_value'],
                            'internal_out_qty'        => $summary['internal_out_qty'],
                            'consumption_qty'         => $summary['consumption_qty'],
                            'out_qty'                 => $summary['out_qty'],
                            'avg_cost'                => $summary['avg_cost'],
                            'closing_qty_theoretical' => $summary['closing_qty_theoretical'],
                            'closing_value'           => $summary['closing_value'],
                            'branch_dispatches'       => $summary['branch_dispatches'],
                        ]
                    );
                }
            }
        }
    }

    public function calculateLineCost(
        string $voucherType,
        ?string $clientId,
        string $itemId,
        float $qty,
        float $cost,
    ): float {
        if (!in_array($voucherType, ['dispatch', 'opening']) || $cost > 0) {
            return $cost;
        }
        $item = Item::where('id', $itemId)->when($clientId, fn($q) => $q->where('client_id', $clientId))->first();
        if ($item && $item->default_cost > 0) {
            return round(abs($qty) * $item->default_cost, 2);
        }
        return $cost;
    }

    public function createDispatchLine(
        string $orderId,
        string $itemId,
        string $warehouseId,
        float $qty,
        float $cost,
        float $unitCost,
        ?string $date,
    ): DispatchLine {
        return DispatchLine::create([
            'order_id'     => $orderId,
            'item_id'      => $itemId,
            'warehouse_id' => $warehouseId,
            'qty'          => $qty,
            'total_cost'   => $cost,
            'unit_cost'    => $unitCost,
            'date'         => $date,
        ]);
    }
}
