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

        return (float) $query->sum('qty');
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

        // أول المدة
        $openingQty   = $this->currentStock($clientId, $warehouseId, $itemId, $prevMonth);
        $openingAvg   = $this->weightedAverageCost($clientId, $warehouseId, $itemId, $prevMonth);
        $openingValue = round($openingQty * $openingAvg, 2);

        // الوارد في الشهر ده
        $inData = StockLedger::where('client_id', $clientId)
            ->where('warehouse_id', $warehouseId)
            ->where('item_id', $itemId)
            ->whereIn('movement_type', ['in', 'transfer_in'])
            ->whereBetween('date', [$startOfMonth->toDateString(), $endOfMonth->toDateString()])
            ->selectRaw('SUM(qty) as qty, SUM(total_cost) as cost')
            ->first();

        $inQty   = (float) ($inData->qty ?? 0);
        $inCost  = (float) ($inData->cost ?? 0);

        // المنصرف في الشهر ده
        $outQty = abs((float) StockLedger::where('client_id', $clientId)
            ->where('warehouse_id', $warehouseId)
            ->where('item_id', $itemId)
            ->whereIn('movement_type', ['out', 'transfer_out'])
            ->whereBetween('date', [$startOfMonth->toDateString(), $endOfMonth->toDateString()])
            ->sum('qty'));

        // الـ Weighted Average للشهر كله
        $totalQty   = $openingQty + $inQty;
        $totalValue = $openingValue + $inCost;
        $avgCost    = $totalQty > 0 ? round($totalValue / $totalQty, 4) : 0;

        // آخر المدة (نظري)
        $closingQtyTheoretical = round($openingQty + $inQty - $outQty, 3);
        $closingValue          = round($closingQtyTheoretical * $avgCost, 2);

        return [
            'opening_qty'            => $openingQty,
            'opening_value'          => $openingValue,
            'in_qty'                 => $inQty,
            'in_value'               => $inCost,
            'out_qty'                => $outQty,
            'avg_cost'               => $avgCost,
            'closing_qty_theoretical'=> $closingQtyTheoretical,
            'closing_value'          => $closingValue,
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
        $startOfMonth = Carbon::parse($month . '-01');
        $endOfMonth   = $startOfMonth->copy()->endOfMonth();
        $prevMonth    = $startOfMonth->copy()->subDay()->toDateString();
        $results      = [];

        DB::transaction(function () use (
            $clientId,
            $warehouseId,
            $month,
            $startOfMonth,
            $endOfMonth,
            $prevMonth,
            &$results
        ) {
            // الاحتفاظ بالجرد الفعلي السابق لو كان مدخل
            $existingActuals = MonthlyClosing::where('client_id', $clientId)
                ->where('warehouse_id', $warehouseId)
                ->where('month', $month)
                ->whereNotNull('closing_qty_actual')
                ->pluck('closing_qty_actual', 'item_id');

            // تنظيف نتائج الشهر الحالي عشان ميبقاش في بيانات stale
            MonthlyClosing::where('client_id', $clientId)
                ->where('warehouse_id', $warehouseId)
                ->where('month', $month)
                ->delete();

            // بيانات الأصناف النشطة
            $items = Item::where('client_id', $clientId)
                ->where('is_active', true)
                ->pluck('name', 'id');

            // تجميعة ما قبل بداية الشهر (لاستخراج أول المدة)
            $openingAgg = StockLedger::where('client_id', $clientId)
                ->where('warehouse_id', $warehouseId)
                ->where('date', '<=', $prevMonth)
                ->selectRaw("\n                    item_id,\n                    SUM(CASE WHEN movement_type IN ('in','transfer_in') THEN qty ELSE 0 END) AS in_qty_before,\n                    SUM(CASE WHEN movement_type IN ('in','transfer_in') THEN total_cost ELSE 0 END) AS in_cost_before,\n                    SUM(CASE WHEN movement_type IN ('out','transfer_out') THEN qty ELSE 0 END) AS out_qty_before\n                ")
                ->groupBy('item_id')
                ->get()
                ->keyBy('item_id');

            // تجميعة حركات الشهر الحالي
            $monthAgg = StockLedger::where('client_id', $clientId)
                ->where('warehouse_id', $warehouseId)
                ->whereBetween('date', [$startOfMonth->toDateString(), $endOfMonth->toDateString()])
                ->selectRaw("\n                    item_id,\n                    SUM(CASE WHEN movement_type IN ('in','transfer_in') THEN qty ELSE 0 END) AS in_qty_month,\n                    SUM(CASE WHEN movement_type IN ('in','transfer_in') THEN total_cost ELSE 0 END) AS in_cost_month,\n                    SUM(CASE WHEN movement_type IN ('out','transfer_out') THEN qty ELSE 0 END) AS out_qty_month\n                ")
                ->groupBy('item_id')
                ->get()
                ->keyBy('item_id');

            $now = now();
            $rowsToInsert = [];

            foreach ($items as $itemId => $itemName) {
                $openRow = $openingAgg->get($itemId);
                $movRow  = $monthAgg->get($itemId);

                $inQtyBefore  = (float) ($openRow->in_qty_before ?? 0);
                $inCostBefore = (float) ($openRow->in_cost_before ?? 0);
                $outQtyBefore = (float) ($openRow->out_qty_before ?? 0);

                $openingQty = round($inQtyBefore - $outQtyBefore, 3);
                $openingAvg = $inQtyBefore > 0 ? round($inCostBefore / $inQtyBefore, 4) : 0;
                $openingVal = round($openingQty * $openingAvg, 2);

                $inQtyMonth  = (float) ($movRow->in_qty_month ?? 0);
                $inCostMonth = (float) ($movRow->in_cost_month ?? 0);
                $outQtyMonth = (float) ($movRow->out_qty_month ?? 0);

                if ($openingQty == 0.0 && $inQtyMonth == 0.0 && $outQtyMonth == 0.0) {
                    continue;
                }

                $totalQty = $openingQty + $inQtyMonth;
                $totalVal = $openingVal + $inCostMonth;
                $avgCost  = $totalQty > 0 ? round($totalVal / $totalQty, 4) : 0;

                $closingQty = round($openingQty + $inQtyMonth - $outQtyMonth, 3);
                $closingVal = round($closingQty * $avgCost, 2);

                $actualQty = $existingActuals[$itemId] ?? null;
                $diffQty   = 0;
                $diffVal   = 0;
                if ($actualQty !== null) {
                    $actualQty = (float) $actualQty;
                    $diffQty   = round($closingQty - $actualQty, 3);
                    $diffVal   = round($diffQty * $avgCost, 2);
                }

                $rowsToInsert[] = [
                    'client_id'               => $clientId,
                    'warehouse_id'            => $warehouseId,
                    'item_id'                 => $itemId,
                    'month'                   => $month,
                    'opening_qty'             => $openingQty,
                    'opening_value'           => $openingVal,
                    'in_qty'                  => $inQtyMonth,
                    'in_value'                => $inCostMonth,
                    'out_qty'                 => $outQtyMonth,
                    'avg_cost'                => $avgCost,
                    'closing_qty_theoretical' => $closingQty,
                    'closing_qty_actual'      => $actualQty,
                    'closing_value'           => $closingVal,
                    'diff_qty'                => $diffQty,
                    'diff_value'              => $diffVal,
                    'created_at'              => $now,
                    'updated_at'              => $now,
                ];

                $results[] = [
                    'item_name'               => $itemName,
                    'opening_qty'             => $openingQty,
                    'opening_value'           => $openingVal,
                    'in_qty'                  => $inQtyMonth,
                    'in_value'                => $inCostMonth,
                    'out_qty'                 => $outQtyMonth,
                    'avg_cost'                => $avgCost,
                    'closing_qty_theoretical' => $closingQty,
                    'closing_value'           => $closingVal,
                ];
            }

            if (!empty($rowsToInsert)) {
                MonthlyClosing::insert($rowsToInsert);
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
