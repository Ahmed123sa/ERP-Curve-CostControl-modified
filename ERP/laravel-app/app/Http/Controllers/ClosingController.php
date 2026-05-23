<?php

namespace App\Http\Controllers;

use App\Http\Requests\ClosingGenerateRequest;
use App\Http\Requests\UpdateActualRequest;
use App\Http\Requests\VoucherConfirmRequest;
use App\Http\Requests\VoucherManualRequest;
use App\Http\Requests\VoucherUploadRequest;
use App\Services\CostCalculationService;
use App\Models\MonthlyClosing;
use App\Models\Warehouse;
use App\Models\Item;
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
