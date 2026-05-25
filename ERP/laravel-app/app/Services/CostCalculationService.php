<?php

namespace App\Services;

use App\Models\StockLedger;
use App\Models\MonthlyClosing;
use App\Models\Item;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

/**
 * CostCalculationService
 * كل الحسابات المالية:
 * - Weighted Average Cost (متوسط مرجح)
 * - رصيد المخزن اللحظي
 * - التقفيل الشهري
 * - الفروق
 */
class CostCalculationService
{
    /**
     * حساب الـ Weighted Average Cost لصنف في مخزن
     * = (قيمة أول المدة + قيمة الوارد) ÷ (كمية أول المدة + كمية الوارد)
     * نفس المعادلة في الشيت بالظبط
     */
    public function weightedAverageCost(
        string $clientId,
        string $warehouseId,
        string $itemId,
        ?string $asOfDate = null
    ): float {
        $query = StockLedger::where('client_id', $clientId)
            ->where('warehouse_id', $warehouseId)
            ->where('item_id', $itemId)
            ->whereIn('movement_type', ['in', 'transfer_in']);

        if ($asOfDate) {
            $query->where('date', '<=', $asOfDate);
        }

        $result = $query->selectRaw('SUM(qty) as total_qty, SUM(total_cost) as total_cost')
            ->first();

        if (!$result || $result->total_qty <= 0) {
            return 0.0;
        }

        return round($result->total_cost / $result->total_qty, 4);
    }

    /**
     * رصيد المخزن الحالي (الكمية)
     * = SUM(qty) من الـ ledger — موجب للـ in، سالب للـ out
     */
    public function currentStock(
        string $clientId,
        string $warehouseId,
        string $itemId,
        ?string $asOfDate = null
    ): float {
        $query = StockLedger::where('client_id', $clientId)
            ->where('warehouse_id', $warehouseId)
            ->where('item_id', $itemId);

        if ($asOfDate) {
            $query->where('date', '<=', $asOfDate);
        }

        $result = $query->selectRaw("
            SUM(CASE WHEN movement_type IN ('in','transfer_in')  THEN qty ELSE 0 END) -
            SUM(CASE WHEN movement_type IN ('out','transfer_out') THEN qty ELSE 0 END) AS balance
        ")->value('balance');

        return round((float) $result, 3);
    }

    /**
     * قيمة المخزن الحالية
     */
    public function currentStockValue(
        string $clientId,
        string $warehouseId,
        string $itemId,
        ?string $asOfDate = null
    ): float {
        $qty = $this->currentStock($clientId, $warehouseId, $itemId, $asOfDate);
        $avg = $this->weightedAverageCost($clientId, $warehouseId, $itemId, $asOfDate);
        return round($qty * $avg, 2);
    }

    /**
     * ملخص حركة صنف في شهر معين لمخزن معين
     * نفس أعمدة شيت "وارد مخزن"
     */
    public function itemMonthSummary(
        string $clientId,
        string $warehouseId,
        string $itemId,
        string $month // 2024-04
    ): array {
        $startOfMonth = Carbon::parse($month . '-01');
        $endOfMonth   = $startOfMonth->copy()->endOfMonth();
        $prevMonth    = $startOfMonth->copy()->subDay()->toDateString();

        // 2. تجميع الحركات في الشهر الحالي
        $ledger = StockLedger::where('client_id', $clientId)
            ->where('warehouse_id', $warehouseId)
            ->where('item_id', $itemId)
            ->whereBetween('date', [$startOfMonth->toDateString(), $endOfMonth->toDateString()])
            ->get();

        // أرصدة افتتاحية مسجلة في هذا الشهر (مهمة جداً للبداية)
        $openingQtyThisMonth = (float) $ledger->where('voucher_type', 'opening')->sum('qty');
        $openingValThisMonth = (float) $ledger->where('voucher_type', 'opening')->sum('total_cost');

        // أول المدة = الرصيد الافتتاحي اللي المستخدم دخله (ثابت، مش بيتأثر بالمسحوبات)
        // لو مفيش رصيد افتتاحي للشهر الحالي → نستخدم رصيد نهاية الشهر السابق كـ fallback
        $hasOpeningThisMonth = $ledger->where('voucher_type', 'opening')->isNotEmpty();
        if ($hasOpeningThisMonth) {
            $openingQty   = $openingQtyThisMonth;
            $openingValue = $openingValThisMonth;
        } else {
            $openingQtyFromPrev = $this->currentStock($clientId, $warehouseId, $itemId, $prevMonth);
            $openingAvgFromPrev = $this->weightedAverageCost($clientId, $warehouseId, $itemId, $prevMonth);
            $openingValueFromPrev = round($openingQtyFromPrev * $openingAvgFromPrev, 2);
            $openingQty   = $openingQtyFromPrev;
            $openingValue = $openingValueFromPrev;
        }

        // مشتريات (خارجي)
        $purchasesQty = (float) $ledger->where('voucher_type', 'purchase')->where('movement_type', 'in')->sum('qty');
        $purchasesVal = (float) $ledger->where('voucher_type', 'purchase')->where('movement_type', 'in')->sum('total_cost');

        // استلامات داخلية (من مخازن أخرى)
        $internalInQty = (float) $ledger->where('voucher_type', 'dispatch')->whereIn('movement_type', ['in', 'transfer_in'])->sum('qty');
        $internalInVal = (float) $ledger->where('voucher_type', 'dispatch')->whereIn('movement_type', ['in', 'transfer_in'])->sum('total_cost');

        // إنتاج (وصفات) — المنتج النهائي يدخل المخزن
        $productionInQty = (float) $ledger->where('voucher_type', 'production')->where('movement_type', 'in')->sum('qty');
        $productionInVal = (float) $ledger->where('voucher_type', 'production')->where('movement_type', 'in')->sum('total_cost');

        // منصرف داخلي (لفروع/مخازن أخرى)
        $internalOutQty = (float) $ledger->where('voucher_type', 'dispatch')->whereIn('movement_type', ['out', 'transfer_out'])->sum('qty');

        // استهلاك/مبيعات/إنتاج
        $consumptionQty = (float) $ledger->whereIn('voucher_type', ['production', 'external_sale', 'withdrawal'])->where('movement_type', 'out')->sum('qty');

        // مرتجعات وتسويات (موجود في الـ ledger لكن لم يكن مصنفاً)
        $returnsInQty    = (float) $ledger->where('voucher_type', 'return')->where('movement_type', 'in')->sum('qty');
        $returnsInVal    = (float) $ledger->where('voucher_type', 'return')->where('movement_type', 'in')->sum('total_cost');
        $returnsOutQty   = (float) $ledger->where('voucher_type', 'return')->where('movement_type', 'out')->sum('qty');
        $adjustmentsInQty = (float) $ledger->where('voucher_type', 'adjustment')->where('movement_type', 'in')->sum('qty');
        $adjustmentsInVal = (float) $ledger->where('voucher_type', 'adjustment')->where('movement_type', 'in')->sum('total_cost');
        $adjustmentsOutQty = (float) $ledger->where('voucher_type', 'adjustment')->where('movement_type', 'out')->sum('qty');

        // إجمالي الوارد والمنصرف (بدون الـ Opening لأنه انجمع فوق)
        $inQty        = $purchasesQty + $internalInQty + $productionInQty + $returnsInQty + $adjustmentsInQty;
        $inValueTotal = $purchasesVal + $internalInVal + $productionInVal + $returnsInVal + $adjustmentsInVal;
        $outQty       = $internalOutQty + $consumptionQty + $returnsOutQty + $adjustmentsOutQty;
        $totalQty     = $openingQty + $inQty;
        $totalValue   = $openingValue + $inValueTotal;

        // 3. سعر الصنف الافتراضي هو الأساس (بيتحدث من الوصفات أو من وارد المخزن)
        $item = \App\Models\Item::find($itemId);
        $defaultCost = (float) ($item->default_cost ?? 0);
        $calcAvg = $totalQty > 0 ? round($totalValue / $totalQty, 4) : 0;

        $avgCost = $calcAvg > 0 ? $calcAvg : $defaultCost;

        // لو لسه صفر — نجيب آخر متوسط مسجل
        if ($avgCost <= 0 && $totalQty > 0) {
            $avgCost = $this->weightedAverageCost($clientId, $warehouseId, $itemId, $prevMonth);
        }

        // الآن نحسب القيم المالية بناءً على السعر النهائي (سواء المحسوب أو الـ fallback)
        if ($openingValue <= 0 && $openingQty != 0) {
            $openingValue = round($openingQty * $avgCost, 2);
        }

        // 4. آخر المدة (نظري)
        $closingQtyTheoretical = round($openingQty + $inQty - $outQty, 3);
        $closingValue          = round($closingQtyTheoretical * $avgCost, 2);

        // 5. المنصرف موزعاً على المواقع المستلمة (فروع أو مخازن)
        $branchDispatches = DB::table('stock_ledger')
            ->join('dispatch_orders', 'stock_ledger.ref_id', '=', 'dispatch_orders.id')
            ->join('warehouses as dest', function($join) {
                $join->on('dispatch_orders.branch_id', '=', 'dest.id')
                     ->orOn('dispatch_orders.warehouse_id', '=', 'dest.id');
            })
            ->where('stock_ledger.client_id', $clientId)
            ->where('stock_ledger.warehouse_id', $warehouseId)
            ->where('stock_ledger.item_id', $itemId)
            ->where('stock_ledger.ref_type', 'dispatch_order')
            ->whereIn('stock_ledger.movement_type', ['out', 'transfer_out', 'dispatch'])
            ->where('dest.id', '!=', $warehouseId) // الوجهة لازم تكون مختلفة
            ->whereBetween('stock_ledger.date', [$startOfMonth->toDateString(), $endOfMonth->toDateString()])
            ->selectRaw('dest.id as branch_id, dest.name as branch_name, SUM(ABS(stock_ledger.qty)) as qty')
            ->groupBy('dest.id', 'dest.name')
            ->get()
            ->keyBy('branch_id')
            ->toArray();

        return [
            'opening_qty'            => $openingQty,
            'opening_value'          => $openingValue,
            'purchases_qty'          => $purchasesQty,
            'purchases_value'        => $purchasesVal,
            'internal_in_qty'        => $internalInQty,
            'internal_in_value'      => $internalInVal,
            'internal_out_qty'       => $internalOutQty,
            'production_in_qty'      => $productionInQty,
            'production_in_value'    => $productionInVal,
            'consumption_qty'        => $consumptionQty,
            'in_qty'                 => $inQty,
            'in_value'               => $inValueTotal,
            'out_qty'                => $outQty,
            'avg_cost'               => $avgCost,
            'closing_qty_theoretical'=> $closingQtyTheoretical,
            'closing_value'          => $closingValue,
            'branch_dispatches'      => $branchDispatches,
        ];
    }

    /**
     * تقفيل الشهر لعميل ومخزن
     * بيحسب كل الأصناف ويحفظ في monthly_closings
     */
    public function generateMonthlyClosing(
        string $clientId,
        string $warehouseId,
        string $month
    ): array {
        $items   = Item::where('client_id', $clientId)->where('is_active', true)->get();
        $results = [];

        DB::transaction(function () use ($clientId, $warehouseId, $month, $items, &$results) {
            // ملاحظة: لا نحذف السجلات (delete) لكي لا نفقد الجرد الفعلي (closing_qty_actual) الذي أدخله المستخدم يدويًا
            
            foreach ($items as $item) {
                $summary = $this->itemMonthSummary($clientId, $warehouseId, $item->id, $month);

// لو الصنف مش active — نمسح التقفيل
                if (!$item->is_active) {
                    MonthlyClosing::where('client_id', $clientId)
                        ->where('warehouse_id', $warehouseId)
                        ->where('item_id', $item->id)
                        ->where('month', $month)
                        ->delete();
                    continue;
                }

                // لو مفيش أي حركة خالص — نحذف أي تقفيل سابق (عشان لو اتمسح إذن محدثش)
                if ($summary['opening_qty'] == 0 && $summary['in_qty'] == 0 && $summary['out_qty'] == 0) {
                    MonthlyClosing::where('client_id', $clientId)
                        ->where('warehouse_id', $warehouseId)
                        ->where('item_id', $item->id)
                        ->where('month', $month)
                        ->delete();
                    continue;
                }

                $closing = MonthlyClosing::updateOrCreate(
                    [
                        'client_id'    => $clientId,
                        'warehouse_id' => $warehouseId,
                        'item_id'      => $item->id,
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

                // حساب الفرق لو في جرد فعلي
                if ($closing->closing_qty_actual !== null) {
                    $closing->diff_qty   = round($closing->closing_qty_theoretical - $closing->closing_qty_actual, 3);
                    $closing->diff_value = round($closing->diff_qty * $summary['avg_cost'], 2);
                    $closing->save();
                }

                $results[] = array_merge(['item_name' => $item->name], $summary);
            }
        });

        return $results;
    }

    /**
     * حساب الـ Food Cost %
     * = قيمة المنصرف ÷ إجمالي المبيعات × 100
     */
    public function foodCostPercent(string $clientId, string $month, float $totalSales): float
    {
        $startOfMonth = Carbon::parse($month . '-01');
        $endOfMonth   = $startOfMonth->copy()->endOfMonth();

        $outValue = StockLedger::where('client_id', $clientId)
            ->whereIn('movement_type', ['out'])
            ->whereBetween('date', [$startOfMonth->toDateString(), $endOfMonth->toDateString()])
            ->selectRaw('SUM(ABS(qty) * unit_cost) as total')
            ->value('total');

        if ($totalSales <= 0) return 0.0;
        return round(($outValue / $totalSales) * 100, 2);
    }
}
