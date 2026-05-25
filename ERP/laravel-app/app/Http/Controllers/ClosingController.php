<?php

namespace App\Http\Controllers;

use App\Http\Requests\ClosingGenerateRequest;
use App\Http\Requests\UpdateActualRequest;
use App\Services\CostCalculationService;
use App\Models\MonthlyClosing;
use App\Models\Warehouse;
use App\Models\Item;
use App\Models\DispatchLine;
use App\Models\DispatchOrder;
use App\Models\StockLedger;
use App\Services\ActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;

class ClosingController extends Controller
{
    public function __construct(private CostCalculationService $calc) {}

    public function index(Request $request): JsonResponse
    {
        $clientId    = $request->user()->current_client_id;
        $warehouseId = $request->warehouse_id;
        $month       = $request->month ?? now()->format('Y-m');

        $closings = MonthlyClosing::where('client_id', $clientId)
            ->where('warehouse_id', $warehouseId)
            ->where('month', $month)
            ->with('item:id,name,unit')
            ->get()
            ->keyBy('item_id');

        $items = Item::where('client_id', $clientId)
            ->where('is_active', true)
            ->orderByRaw('COALESCE(sort_order, 999999), name')
            ->get(['id', 'name', 'unit', 'default_cost']);

        $rows = $items->map(function ($item) use ($closings) {
            $r = $closings->get($item->id);

            $avgCost = 0;
            if ($r && $r->avg_cost > 0) {
                $avgCost = (float) $r->avg_cost;
            }
            if ($avgCost <= 0) {
                $avgCost = (float) ($item->default_cost ?? 0);
            }

            $openingQty = (float) ($r->opening_qty ?? 0);
            $inQty = (float) ($r->in_qty ?? 0);
            $outQty = (float) ($r->out_qty ?? 0);
            $closingQtyTheoretical = (float) ($r->closing_qty_theoretical ?? ($openingQty + $inQty - $outQty));

            $openingValue = (float) ($r->opening_value ?? round($openingQty * $avgCost, 2));
            // لو قيمة الوارد صفر بس في كمية — نحسبها من متوسط السعر
            $rawInValue = (float) ($r->in_value ?? 0);
            $inValue = $rawInValue > 0 ? $rawInValue : round($inQty * $avgCost, 2);
            $closingValue = (float) ($r->closing_value ?? round($closingQtyTheoretical * $avgCost, 2));

            return [
                'id'                       => $r?->id ?? ('item-' . $item->id),
                'item_id'                  => $item->id,
                'item_name'                => $item->name,
                'unit'                     => $item->unit,
                'opening_qty'              => $openingQty,
                'opening_value'            => $openingValue,
                'in_qty'                   => $inQty,
                'in_value'                 => $inValue,
                'out_qty'                  => $outQty,
                'avg_cost'                 => $avgCost,
                'closing_qty_theoretical'  => $closingQtyTheoretical,
                'closing_qty_actual'       => $r->closing_qty_actual ?? null,
                'physical_count'           => $r->physical_count ?? null,
                'closing_value'            => $closingValue,
                'diff_qty'                 => (float) ($r->diff_qty ?? 0),
                'diff_value'               => (float) ($r->diff_value ?? 0),
                'is_locked'                => (bool) ($r->is_locked ?? false),
                'branch_dispatches'        => $r->branch_dispatches ?? [],
            ];
        })->values();

        return response()->json(['data' => $rows]);
    }

    /**
     * تقفيل شامل — كل المخازن في شاشة واحدة
     */
    public function allWarehouses(Request $request): JsonResponse
    {
        $clientId = $request->user()->current_client_id;
        $month    = $request->month ?? now()->format('Y-m');

        $closings = MonthlyClosing::where('client_id', $clientId)
            ->where('month', $month)
            ->with('item:id,name,unit', 'warehouse:id,name,type')
            ->orderBy('warehouse_id')
            ->orderBy('item_id')
            ->get();

        $grouped = $closings->groupBy('warehouse_id')->map(function ($items) {
            return [
                'warehouse' => [
                    'id'   => $items->first()->warehouse_id,
                    'name' => $items->first()->warehouse->name,
                    'type' => $items->first()->warehouse->type,
                ],
                'items' => $items->map(fn($r) => [
                    'id'                       => $r->id,
                    'item_name'                => $r->item->name,
                    'unit'                     => $r->item->unit,
                    'opening_qty'              => $r->opening_qty,
                    'opening_value'            => $r->opening_value,
                    'in_qty'                   => $r->in_qty,
                    'in_value'                 => $r->in_value,
                    'out_qty'                  => $r->out_qty,
                    'avg_cost'                 => $r->avg_cost ?? 0,
                    'closing_qty_theoretical'  => $r->closing_qty_theoretical,
                    'closing_qty_actual'       => $r->closing_qty_actual,
                    'closing_value'            => $r->closing_value,
                    'diff_qty'                 => $r->diff_qty,
                    'diff_value'               => $r->diff_value,
                    'is_locked'                => $r->is_locked,
                    'branch_dispatches'        => $r->branch_dispatches ?? [],
                ]),
                'totals' => [
                    'opening_value'   => $items->sum('opening_value'),
                    'in_value'        => $items->sum('in_value'),
                    'closing_value'   => $items->sum('closing_value'),
                    'total_diff_value'=> $items->sum('diff_value'),
                ],
            ];
        });

        $summary = [
            'total_opening_value'  => $closings->sum('opening_value'),
            'total_in_value'       => $closings->sum('in_value'),
            'total_closing_value'  => $closings->sum('closing_value'),
            'total_diff_value'     => $closings->sum('diff_value'),
        ];

        return response()->json([
            'month'      => $month,
            'summary'    => $summary,
            'warehouses' => $grouped->values(),
        ]);
    }

    public function generate(ClosingGenerateRequest $request): JsonResponse
    {
        $clientId = $request->user()->current_client_id;
        $month    = $request->month;
        $whId     = $request->warehouse_id;

        try {
            if ($whId === 'all') {
                $warehouses = Warehouse::where('client_id', $clientId)->where('is_active', true)->get();
                foreach ($warehouses as $wh) {
                    $this->calc->generateMonthlyClosing($clientId, $wh->id, $month);
                }
                return response()->json(['message' => 'تم توليد التقفيل لكل المخازن والفروع بنجاح']);
            }

            $results = $this->calc->generateMonthlyClosing($clientId, $whId, $month);
            return response()->json(['message' => 'تم توليد التقفيل بنجاح', 'count' => count($results)]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'خطأ في توليد التقفيل',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    public function updateActual(UpdateActualRequest $request, MonthlyClosing $closing): JsonResponse
    {
        abort_if($closing->is_locked, 403, 'الشهر مقفول');

        $actual = (float) $request->closing_qty_actual;

        $closing->closing_qty_actual = $actual;
        $closing->diff_qty           = round($closing->closing_qty_theoretical - $actual, 3);
        $closing->diff_value         = round($closing->diff_qty * $closing->avg_cost, 2);
        $closing->save();

        return response()->json(['message' => 'تم التحديث', 'diff_qty' => $closing->diff_qty, 'diff_value' => $closing->diff_value]);
    }

    public function bulkUpdateActual(Request $request): JsonResponse
    {
        $request->validate([
            'warehouse_id' => 'required|uuid',
            'month'        => 'required|date_format:Y-m',
            'lines'        => 'required|array',
            'lines.*.item_id' => 'required|uuid',
            'lines.*.qty'     => 'required|numeric|min:0',
        ]);

        $clientId = $request->user()->current_client_id;
        $count = 0;

        foreach ($request->lines as $line) {
            MonthlyClosing::updateOrCreate(
                [
                    'client_id'    => $clientId,
                    'warehouse_id' => $request->warehouse_id,
                    'item_id'      => $line['item_id'],
                    'month'        => $request->month,
                ],
                [
                    'physical_count' => $line['qty'] // نتحفظ بالرقم الأصلي هنا
                ]
            );
            $count++;
        }

        return response()->json(['message' => "تم حفظ جرد {$count} صنف بنجاح في موديول الجرد النهائي"]);
    }

    /**
     * مزامنة الجرد النهائي إلى الماتريكس
     * بينسخ الـ physical_count إلى الـ closing_qty_actual
     */
    public function syncPhysicalToActual(Request $request): JsonResponse
    {
        $request->validate([
            'warehouse_id' => 'required', // ممكن يكون uuid أو 'all'
            'month'        => 'required|date_format:Y-m',
        ]);

        $clientId = $request->user()->current_client_id;
        $whId     = $request->warehouse_id;

        $query = MonthlyClosing::where('client_id', $clientId)
            ->where('month', $request->month)
            ->whereNotNull('physical_count');

        if ($whId !== 'all') {
            $query->where('warehouse_id', $whId);
        }

        $closings = $query->get();
        $updated  = 0;

        foreach ($closings as $c) {
            $c->closing_qty_actual = $c->physical_count;
            $c->diff_qty   = round($c->closing_qty_theoretical - $c->closing_qty_actual, 3);
            $c->diff_value = round($c->diff_qty * $c->avg_cost, 2);
            $c->save();
            $updated++;
        }

        return response()->json(['message' => "تم تحميل الجرد النهائي لعدد {$updated} صنف"]);
    }

    public function lock(Request $request): JsonResponse
    {
        $request->validate([
            'warehouse_id' => 'required|uuid',
            'month'        => 'required|date_format:Y-m',
        ]);

        $clientId = $request->user()->current_client_id;
        $userId   = $request->user()->id;
        $now      = now();

        $count = MonthlyClosing::where('client_id', $clientId)
            ->where('warehouse_id', $request->warehouse_id)
            ->where('month', $request->month)
            ->update([
                'is_locked'  => true,
                'locked_by'  => $userId,
                'locked_at'  => $now,
            ]);

        return response()->json(['message' => "تم إقفال الشهر ({$count} صنف)"]);
    }

    /**
     * تعديل خلية وارد يومي (كمية) في التقفيل ← تحديث الفاتورة الأصلية + إعادة توليد التقفيل
     */
    public function editDailyCell(Request $request): JsonResponse
    {
        $request->validate([
            'warehouse_id'  => 'required|uuid',
            'item_id'       => 'required|uuid',
            'date'          => 'required|date',
            'month'         => 'required|date_format:Y-m',
            'lines'         => 'required|array|min:1',
            'lines.*.order_id' => 'required|uuid',
            'lines.*.qty'      => 'required|numeric|min:0',
        ]);

        $clientId = $request->user()->current_client_id;

        // 1. تحقق من القفل
        $locked = MonthlyClosing::where('client_id', $clientId)
            ->where('warehouse_id', $request->warehouse_id)
            ->where('item_id', $request->item_id)
            ->where('month', $request->month)
            ->value('is_locked');

        if ($locked) {
            abort(403, 'الشهر مقفول — لا يمكن التعديل');
        }

        $updatedOrders = 0;

        DB::transaction(function () use ($request, $clientId, &$updatedOrders) {
            foreach ($request->lines as $line) {
                $dispatchLine = DispatchLine::where('client_id', $clientId)
                    ->where('order_id', $line['order_id'])
                    ->where('item_id', $request->item_id)
                    ->first();

                if (!$dispatchLine) continue;

                $oldQty   = (float) $dispatchLine->qty;
                $newQty   = (float) $line['qty'];
                $unitCost = (float) $dispatchLine->unit_cost;

                if (abs($newQty - $oldQty) < 0.001) continue;

                // 2. تحديث DispatchLine
                $dispatchLine->qty = round($newQty, 3);
                if ($unitCost > 0) {
                    $dispatchLine->total_cost = round($newQty * $unitCost, 2);
                }
                $dispatchLine->save();

                // 3. تحديث StockLedger (كل الجهات — مخزن وفرع)
                $ledgerEntries = StockLedger::where('client_id', $clientId)
                    ->where('ref_type', 'dispatch_order')
                    ->where('ref_id', $line['order_id'])
                    ->where('item_id', $request->item_id)
                    ->get();

                foreach ($ledgerEntries as $entry) {
                    $entry->qty = round($newQty, 3);
                    if ($unitCost > 0) {
                        $entry->total_cost = round($newQty * $unitCost, 2);
                        $entry->unit_cost  = $unitCost;
                    }
                    $entry->save();
                }

                // 4. تسجيل النشاط
                ActivityLogger::log(
                    action:     'closing_cell_edited',
                    entityType: 'DispatchLine',
                    entityId:   $dispatchLine->id,
                    oldValues:  ['qty' => $oldQty],
                    newValues:  ['qty' => $newQty, 'source' => 'closing_edit_mode'],
                );

                // 5. لو الفاتورة من نوع purchase — نحدث default_cost
                $order = DispatchOrder::where('client_id', $clientId)->find($line['order_id']);
                if ($order && $order->type === 'purchase' && $unitCost > 0) {
                    $item = Item::find($request->item_id);
                    if ($item) {
                        $oldCost = $item->default_cost;
                        $item->default_cost = $unitCost;
                        $item->save();

                        if ((float) $oldCost !== $unitCost) {
                            ActivityLogger::log(
                                action:     'price_updated',
                                entityType: 'Item',
                                entityId:   $item->id,
                                oldValues:  ['default_cost' => $oldCost],
                                newValues:  ['default_cost' => $unitCost, 'source' => 'closing_edit_mode'],
                            );
                        }
                    }
                }

                $updatedOrders++;
            }

            // 6. إعادة توليد التقفيل للصنف + المخزن
            $summary = $this->calc->itemMonthSummary(
                $clientId, $request->warehouse_id, $request->item_id, $request->month
            );

            $closing = MonthlyClosing::where('client_id', $clientId)
                ->where('warehouse_id', $request->warehouse_id)
                ->where('item_id', $request->item_id)
                ->where('month', $request->month)
                ->first();

            if ($summary['opening_qty'] == 0 && $summary['in_qty'] == 0 && $summary['out_qty'] == 0) {
                if ($closing) $closing->delete();
            } else {
                MonthlyClosing::updateOrCreate(
                    [
                        'client_id'    => $clientId,
                        'warehouse_id' => $request->warehouse_id,
                        'item_id'      => $request->item_id,
                        'month'        => $request->month,
                    ],
                    array_merge($summary, [
                        'is_locked'    => $closing?->is_locked ?? false,
                        'locked_by'    => $closing?->locked_by ?? null,
                        'locked_at'    => $closing?->locked_at ?? null,
                        'closing_qty_actual' => $closing?->closing_qty_actual ?? null,
                        'physical_count'     => $closing?->physical_count ?? null,
                    ])
                );
            }
        });

        return response()->json([
            'message'        => "تم تحديث {$updatedOrders} فاتورة وإعادة توليد التقفيل",
            'updated_orders' => $updatedOrders,
        ]);
    }

    /**
     * جلب تفاصيل فواتير خلية معينة (لـ Popover في Edit Mode)
     */
    public function cellOrders(Request $request): JsonResponse
    {
        $request->validate([
            'warehouse_id' => 'required|uuid',
            'item_id'      => 'required|uuid',
            'date'         => 'required|date',
        ]);

        $clientId = $request->user()->current_client_id;

        $entries = StockLedger::where('client_id', $clientId)
            ->where('warehouse_id', $request->warehouse_id)
            ->where('item_id', $request->item_id)
            ->where('date', $request->date)
            ->whereIn('movement_type', ['in', 'transfer_in'])
            ->get();

        $lines = [];
        foreach ($entries as $entry) {
            $order = DispatchOrder::where('client_id', $clientId)->find($entry->ref_id);
            $lines[] = [
                'order_id'   => $entry->ref_id,
                'order_ref'  => $order ? ($order->id ? '#' . substr($order->id, 0, 8) : '—') : '—',
                'type'       => $order?->type ?? $entry->voucher_type,
                'qty'        => (float) $entry->qty,
                'total_cost' => (float) $entry->total_cost,
                'unit_cost'  => (float) $entry->unit_cost,
            ];
        }

        return response()->json([
            'lines'       => $lines,
            'total_qty'   => round(array_sum(array_column($lines, 'qty')), 3),
            'total_value' => round(array_sum(array_column($lines, 'total_cost')), 2),
        ]);
    }

    /**
     * جلب كل فواتير الشراء لصنف+مخزن في شهر كامل (لـ Popover إجمالي المشتريات)
     */
    public function monthlyOrders(Request $request): JsonResponse
    {
        $request->validate([
            'warehouse_id' => 'required|uuid',
            'item_id'      => 'required|uuid',
            'month'        => 'required|date_format:Y-m',
        ]);

        $clientId = $request->user()->current_client_id;
        $start    = \Carbon\Carbon::parse($request->month . '-01');
        $end      = $start->copy()->endOfMonth();

        $entries = StockLedger::where('client_id', $clientId)
            ->where('warehouse_id', $request->warehouse_id)
            ->where('item_id', $request->item_id)
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->where('movement_type', 'in')
            ->where('voucher_type', 'purchase')
            ->get();

        $lines = [];
        foreach ($entries as $entry) {
            $order = DispatchOrder::where('client_id', $clientId)->find($entry->ref_id);
            $lines[] = [
                'order_id'   => $entry->ref_id,
                'order_ref'  => $entry->ref_id ? '#' . substr($entry->ref_id, 0, 8) : '—',
                'type'       => $order?->type ?? $entry->voucher_type,
                'qty'        => (float) $entry->qty,
                'total_cost' => (float) $entry->total_cost,
                'unit_cost'  => (float) $entry->unit_cost,
                'date'       => $entry->date instanceof \Carbon\Carbon ? $entry->date->toDateString() : $entry->date,
            ];
        }

        return response()->json([
            'lines'       => $lines,
            'total_value' => round(array_sum(array_column($lines, 'total_cost')), 2),
            'message'     => count($lines) > 0 ? null : 'لا توجد مشتريات لهذا الصنف في الشهر',
        ]);
    }

    /**
     * تعديل قيمة إجمالي المشتريات في التقفيل ← تحديث الفاتورة الأصلية + إعادة توليد التقفيل
     */
    public function editCellValue(Request $request): JsonResponse
    {
        $request->validate([
            'warehouse_id'  => 'required|uuid',
            'item_id'       => 'required|uuid',
            'month'         => 'required|date_format:Y-m',
            'lines'         => 'required|array|min:1',
            'lines.*.order_id' => 'required|uuid',
            'lines.*.value'    => 'required|numeric|min:0',
        ]);

        $clientId = $request->user()->current_client_id;

        $locked = MonthlyClosing::where('client_id', $clientId)
            ->where('warehouse_id', $request->warehouse_id)
            ->where('item_id', $request->item_id)
            ->where('month', $request->month)
            ->value('is_locked');

        if ($locked) {
            abort(403, 'الشهر مقفول — لا يمكن التعديل');
        }

        $updatedOrders = 0;

        DB::transaction(function () use ($request, $clientId, &$updatedOrders) {
            foreach ($request->lines as $line) {
                $dispatchLine = DispatchLine::where('client_id', $clientId)
                    ->where('order_id', $line['order_id'])
                    ->where('item_id', $request->item_id)
                    ->first();

                if (!$dispatchLine) continue;

                $oldValue = (float) $dispatchLine->total_cost;
                $newValue = (float) $line['value'];

                if (abs($newValue - $oldValue) < 0.01) continue;

                $dispatchLine->total_cost = round($newValue, 2);
                if ($dispatchLine->qty > 0) {
                    $dispatchLine->unit_cost = round($newValue / $dispatchLine->qty, 4);
                }
                $dispatchLine->save();

                // تحديث StockLedger
                $ledgerEntries = StockLedger::where('client_id', $clientId)
                    ->where('ref_type', 'dispatch_order')
                    ->where('ref_id', $line['order_id'])
                    ->where('item_id', $request->item_id)
                    ->get();

                foreach ($ledgerEntries as $entry) {
                    $entry->total_cost = round($newValue, 2);
                    if ($entry->qty > 0) {
                        $entry->unit_cost = round($newValue / $entry->qty, 4);
                    }
                    $entry->save();
                }

                ActivityLogger::log(
                    action:     'closing_value_edited',
                    entityType: 'DispatchLine',
                    entityId:   $dispatchLine->id,
                    oldValues:  ['total_cost' => $oldValue],
                    newValues:  ['total_cost' => $newValue, 'source' => 'closing_edit_mode'],
                );

                $updatedOrders++;
            }

            // إعادة توليد التقفيل
            $summary = $this->calc->itemMonthSummary(
                $clientId, $request->warehouse_id, $request->item_id, $request->month
            );

            $closing = MonthlyClosing::where('client_id', $clientId)
                ->where('warehouse_id', $request->warehouse_id)
                ->where('item_id', $request->item_id)
                ->where('month', $request->month)
                ->first();

            if ($summary['opening_qty'] == 0 && $summary['in_qty'] == 0 && $summary['out_qty'] == 0) {
                if ($closing) $closing->delete();
            } else {
                MonthlyClosing::updateOrCreate(
                    [
                        'client_id'    => $clientId,
                        'warehouse_id' => $request->warehouse_id,
                        'item_id'      => $request->item_id,
                        'month'        => $request->month,
                    ],
                    array_merge($summary, [
                        'is_locked'    => $closing?->is_locked ?? false,
                        'locked_by'    => $closing?->locked_by ?? null,
                        'locked_at'    => $closing?->locked_at ?? null,
                        'closing_qty_actual' => $closing?->closing_qty_actual ?? null,
                        'physical_count'     => $closing?->physical_count ?? null,
                    ])
                );
            }
        });

        return response()->json([
            'message'        => "تم تحديث قيمة {$updatedOrders} فاتورة وإعادة توليد التقفيل",
            'updated_orders' => $updatedOrders,
        ]);
    }

    public function export(Request $request)
    {
        $clientId    = $request->user()->current_client_id;
        $warehouseId = $request->warehouse_id;
        $month       = $request->month;
        return app(\App\Services\ReportExportService::class)->exportLocationExcel(
            $clientId, $warehouseId, $month
        );
    }

    public function exportPdf(Request $request)
    {
        $clientId = $request->user()->current_client_id;
        return app(\App\Services\ReportExportService::class)->exportLocationPdf(
            $clientId, $request->warehouse_id, $request->month
        );
    }
}
