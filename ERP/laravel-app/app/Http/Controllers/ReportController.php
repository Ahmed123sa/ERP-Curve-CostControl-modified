<?php

namespace App\Http\Controllers;

use App\Models\Item;
use App\Models\Warehouse;
use App\Models\MonthlyClosing;
use App\Models\StockLedger;
use App\Models\DispatchLine;
use App\Models\DispatchOrder;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use App\Services\ReportExportService;

class ReportController extends Controller
{
    /**
     * تقرير الجرد العام (Matrix)
     * يعرض كل الأصناف وكل المخازن والفروع في جدول واحد
     * هو ده الشيت اللي الكوست كنترول بيحبه
     */
    public function grandSummary(Request $request): JsonResponse
    {
        $clientId = $request->user()->current_client_id;
        $month    = $request->month ?? now()->format('Y-m');

        // 1. جيب كل الأصناف بالترتيب
        $items = Item::where('client_id', $clientId)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name', 'unit', 'category']);

        // 2. جيب كل المواقع النشطة (بدون تكرار — نفس الاسم+النوع يعتبر مخزن واحد)
        $locations = Warehouse::where('client_id', $clientId)
            ->where('is_active', true)
            ->get(['id', 'name', 'type'])
            ->groupBy(fn($w) => $w->name . '|' . $w->type)
            ->map(function ($group) use ($clientId, $month) {
                $ids = $group->pluck('id');
                $counts = MonthlyClosing::where('client_id', $clientId)
                    ->where('month', $month)
                    ->whereIn('warehouse_id', $ids)
                    ->selectRaw('warehouse_id, COUNT(*) as cnt')
                    ->groupBy('warehouse_id')
                    ->pluck('cnt', 'warehouse_id');
                return $group->sortByDesc(fn($w) => $counts[$w->id] ?? 0)->first();
            })
            ->values();

        // 3. جيب كل بيانات التقفيل للشهر ده
        $closings = MonthlyClosing::where('client_id', $clientId)
            ->where('month', $month)
            ->get()
            ->groupBy('item_id');

        $matrix = [];
        foreach ($items as $item) {
            $row = [
                'item_id'   => $item->id,
                'item_name' => $item->name,
                'unit'      => $item->unit,
                'category'  => $item->category,
                'locations' => [],
                'totals'    => [
                    'opening_qty'   => 0,
                    'purchases_qty' => 0,
                    'dispatch_qty'  => 0,
                    'consumption_qty' => 0,
                    'theoretical'   => 0,
                    'actual'        => 0,
                    'diff'          => 0,
                ]
            ];

            $itemClosings = $closings->get($item->id, collect());

            foreach ($locations as $loc) {
                $c = $itemClosings->where('warehouse_id', $loc->id)->first();
                
                $data = [
                    'opening'      => $c ? (float)$c->opening_qty : 0,
                    'purchases'    => $c ? (float)$c->in_qty : 0,
                    'internal_in'  => $c ? (float)$c->internal_in_qty : 0,
                    'internal_out' => $c ? (float)$c->internal_out_qty : 0,
                    'consumption'  => $c ? (float)$c->consumption_qty : 0,
                    'theoretical'  => $c ? (float)$c->closing_qty_theoretical : 0,
                    'actual'       => $c ? (float)$c->closing_qty_actual : null,
                ];

                $row['locations'][$loc->id] = $data;

                // أول المدة للمخازن فقط
                if ($loc->type === 'main' || $loc->type === 'sub') {
                    $row['totals']['opening_qty'] += $data['opening'];
                }
                
                // المشتريات للمخازن فقط
                if ($loc->type === 'main' || $loc->type === 'sub') {
                    $row['totals']['purchases_qty'] += $data['purchases'];
                }
                
                // إجمالي المنصرف للفروع (من المخازن)
                $row['totals']['dispatch_qty'] += $data['internal_out'];
                
                // الاستهلاك للمخازن فقط
                if ($loc->type === 'main' || $loc->type === 'sub') {
                    $row['totals']['consumption_qty'] += $data['consumption'];
                }
            }

            // المعادلة: أول المدد + مشتريات المخازن - منصرف الفروع - استهلاك المخازن
            $row['totals']['theoretical'] = round($row['totals']['opening_qty'] + $row['totals']['purchases_qty'] - $row['totals']['dispatch_qty'] - $row['totals']['consumption_qty'], 3);
            
            // الجرد الفعلي الإجمالي (يتم تخزينه في أول سجل متاح للصنف)
            $c = $itemClosings->where('warehouse_id', $locations->firstWhere('type', 'main')->id ?? '')->first() 
                 ?? $itemClosings->first();
            
            $row['totals']['actual'] = $c ? (float)$c->closing_qty_actual : null;
            $row['totals']['closing_id'] = $c ? $c->id : null;
            
            // الفرق = الفعلي - النظري
            $row['totals']['diff'] = $row['totals']['actual'] !== null 
                ? round($row['totals']['actual'] - $row['totals']['theoretical'], 3)
                : 0;
            
            $matrix[] = $row;
        }

        return response()->json([
            'month'     => $month,
            'items'     => $matrix,
            'locations' => $locations,
        ]);
    }

    /**
     * تقرير مراقبة الفرع
     * (مشتريات/استلامات - استهلاك - مخزون)
     */
    public function branchPerformance(Request $request): JsonResponse
    {
        $clientId = $request->user()->current_client_id;
        $branchId = $request->branch_id; // id من جدول warehouses نوعه branch
        $month    = $request->month ?? now()->format('Y-m');

        if (!$branchId) {
            return response()->json(['message' => 'يجب اختيار الفرع'], 400);
        }

        $closings = MonthlyClosing::where('client_id', $clientId)
            ->where('warehouse_id', $branchId)
            ->where('month', $month)
            ->with('item:id,name,unit,default_cost')
            ->get();

        return response()->json([
            'month' => $month,
            'data'  => $closings
        ]);
    }

    /**
     * التفاصيل المالية لكل موقع — Dashboard مالي
     * قيمة أول المدة — قيمة المشتريات — قيمة آخر المدة — قيمة المستلم الفعلي
     * المستلم الفعلي = أول المدة + إجمالي الوارد - آخر المدة
     */
    public function financialDetails(Request $request): JsonResponse
    {
        $data = $this->buildFinancialData(
            $request->user()->current_client_id,
            $request->month,
            $request->warehouse_id
        );

        return response()->json($data);
    }

    /**
     * تصدير التفاصيل المالية إلى Excel
     */
    public function exportFinancialDetails(Request $request)
    {
        $clientId = $request->user()->current_client_id;
        $month    = $request->month ?? now()->format('Y-m');
        $data     = $this->buildFinancialData($clientId, $month, $request->warehouse_id);
        $whFilter = $request->warehouse_id;

        $warehouses = [];
        if ($whFilter) {
            $wh = Warehouse::find($whFilter);
            if ($wh) $warehouses[] = $wh->name;
        }

        $locationLabel = $whFilter && isset($warehouses[0]) ? " — {$warehouses[0]}" : '';
        $filename = "تفاصيل_مالية_{$month}{$locationLabel}.xlsx";

        $rows = [
            ['التفاصيل المالية للمواقع' . ($locationLabel ? " — {$warehouses[0]}" : '') . " — {$month}"],
            [],
            ['الموقع', 'النوع', 'أول المدة', 'مشتريات', 'وارد داخلي', 'آخر المدة (نظري)', 'آخر المدة (فعلي)', 'المستلم الفعلي', 'الفروق', 'الأصناف'],
        ];

        foreach ($data['warehouses'] as $wh) {
            $isBranch = $wh['type'] === 'branch';
            $rows[] = [
                $wh['name'],
                $wh['type'] === 'main' ? 'رئيسي' : ($wh['type'] === 'sub' ? 'فرعي' : ($wh['type'] === 'branch' ? 'فرع' : $wh['type'])),
                $wh['opening_value'],
                $isBranch ? '—' : $wh['purchases_value'],
                $wh['internal_in_value'] ?: '—',
                $isBranch ? '—' : $wh['closing_value'],
                $wh['closing_value_actual'] ?: '—',
                $wh['actual_received'],
                $wh['diff_value'],
                "{$wh['active_items']}/{$wh['item_count']}",
            ];
        }

        // سطر الإجماليات
        $rows[] = [];
        $rows[] = [
            'الإجمالي',
            '',
            $data['summary']['total_opening'],
            $data['summary']['total_purchases'],
            $data['summary']['total_internal_in'],
            $data['summary']['total_closing'],
            $data['summary']['total_closing_actual'],
            $data['summary']['total_received'],
            $data['summary']['total_diff'],
            '',
        ];

        // سطر المعادلة
        $rows[] = [];
        $rows[] = ['* المستلم الفعلي = أول المدة + المشتريات + وارد داخلي - آخر المدة (فعلي إن وجد وإلا صفر)'];

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setRightToLeft(true);

        foreach ($rows as $ri => $row) {
            foreach ($row as $ci => $val) {
                $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($ci + 1);
                $sheet->setCellValue($col . ($ri + 1), $val);
            }
        }

        $writer = new Xlsx($spreadsheet);

        return response()->streamDownload(function () use ($writer) {
            $writer->save('php://output');
        }, $filename, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    /**
     * بناء بيانات التفاصيل المالية (مشترك بين JSON و Excel)
     */
    private function buildFinancialData(string $clientId, ?string $month, ?string $whFilter): array
    {
        $month = $month ?? now()->format('Y-m');
        $startOfMonth = \Carbon\Carbon::parse($month . '-01')->toDateString();
        $endOfMonth   = \Carbon\Carbon::parse($month . '-01')->endOfMonth()->toDateString();

        $closings = MonthlyClosing::where('client_id', $clientId)
            ->where('month', $month)
            ->when($whFilter, fn($q) => $q->where('warehouse_id', $whFilter))
            ->get();

        $warehouses = Warehouse::where('client_id', $clientId)
            ->where('is_active', true)
            ->when($whFilter, fn($q) => $q->where('id', $whFilter))
            ->get()
            ->keyBy('id');

        $grouped = $closings->groupBy('warehouse_id');

        $result = [];
        foreach ($grouped as $whId => $items) {
            $wh = $warehouses->get($whId);
            if (!$wh) continue;

            $openingValue  = (float) $items->sum('opening_value');
            // إجمالي الوارد من الـ ledger مباشرة (مشتريات + داخلي + مرتجعات + تسويات — بدون الافتتاحي)
            // ملاحظة: opening_value من monthly_closings يحتوي الافتتاحي مسبقاً
            $inValue = (float) StockLedger::where('client_id', $clientId)
                ->where('warehouse_id', $whId)
                ->whereBetween('date', [$startOfMonth, $endOfMonth])
                ->whereIn('movement_type', ['in', 'transfer_in'])
                ->where('voucher_type', '!=', 'opening')
                ->sum('total_cost');
            // مشتريات خارجية مباشرة — من الـ ledger مباشرة لتجنب أي انحراف في monthly_closings
            $purchasesValue = (float) StockLedger::where('client_id', $clientId)
                ->where('warehouse_id', $whId)
                ->whereBetween('date', [$startOfMonth, $endOfMonth])
                ->where('voucher_type', 'purchase')
                ->where('movement_type', 'in')
                ->sum('total_cost');
            // وارد داخلي (من مخازن أخرى) — من الـ ledger مباشرة
            $internalInValue = (float) StockLedger::where('client_id', $clientId)
                ->where('warehouse_id', $whId)
                ->whereBetween('date', [$startOfMonth, $endOfMonth])
                ->where('voucher_type', 'dispatch')
                ->whereIn('movement_type', ['in', 'transfer_in'])
                ->sum('total_cost');
            // قيمة المستهلك/المنصرف — من الـ ledger مباشرة
            $consumptionValue = (float) StockLedger::where('client_id', $clientId)
                ->where('warehouse_id', $whId)
                ->whereBetween('date', [$startOfMonth, $endOfMonth])
                ->whereIn('voucher_type', ['production', 'external_sale', 'withdrawal'])
                ->where('movement_type', 'out')
                ->sum('total_cost');
            // قيمة آخر المدة النظري (للمخازن)
            $closingValueTheoretical = (float) $items->sum('closing_value');
            // قيمة آخر المدة الفعلي (حسب الجرد الفعلي × متوسط السعر)
            $closingValueActual = (float) round((float) $items->sum(function ($i) {
                if ($i->closing_qty_actual !== null) {
                    return (float)$i->closing_qty_actual * (float)$i->avg_cost;
                }
                return 0;
            }), 2);
            $hasActual   = $items->contains(fn($i) => $i->closing_qty_actual !== null);
            $outQty      = (float) $items->sum('out_qty');
            $diffValue   = (float) $items->sum('diff_value');
            $activeItems = $items->filter(fn($i) => $i->opening_qty != 0 || $i->in_qty != 0 || $i->out_qty != 0)->count();
            $locked      = $items->first()?->is_locked ?? false;

            // المستلم الفعلي = أول المدة + إجمالي الوارد - آخر المدة (فعلي إن وجد وإلا صفر)
            $closingForFormula = $hasActual ? $closingValueActual : 0;
            $actualReceived = round($openingValue + $inValue - $closingForFormula, 2);

            $result[] = [
                'id'                      => $whId,
                'name'                    => $wh->name,
                'type'                    => $wh->type,
                'locked'                  => (bool) $locked,
                'item_count'              => $items->count(),
                'active_items'            => $activeItems,
                'opening_value'           => $openingValue,
                'purchases_value'         => $purchasesValue,        // مشتريات خارجية
                'internal_in_value'       => $internalInValue,       // وارد داخلي
                'total_in_value'          => $inValue,              // إجمالي الوارد
                'consumption_value'       => $consumptionValue,      // مستهلك/منصرف
                'closing_value'           => $closingValueTheoretical, // نظري
                'closing_value_actual'    => $closingValueActual,      // فعلي
                'has_actual_closing'      => $hasActual,
                'actual_received'         => $actualReceived,
                'out_qty'                 => $outQty,
                'diff_value'              => $diffValue,
            ];
        }

        usort($result, fn($a, $b) => $a['type'] <=> $b['type']);

        $totClosingTheoretical = round(array_sum(array_column($result, 'closing_value')), 2);
        $totClosingActual      = round(array_sum(array_column($result, 'closing_value_actual')), 2);

        return [
            'month'      => $month,
            'warehouses' => $result,
            'summary'    => [
                'total_opening'          => round(array_sum(array_column($result, 'opening_value')), 2),
                'total_purchases'        => round(array_sum(array_column($result, 'purchases_value')), 2),
                'total_internal_in'      => round(array_sum(array_column($result, 'internal_in_value')), 2),
                'total_in'               => round(array_sum(array_column($result, 'total_in_value')), 2),
                'total_closing'          => $totClosingTheoretical,
                'total_closing_actual'   => $totClosingActual,
                'total_received'         => round(array_sum(array_column($result, 'actual_received')), 2),
                'total_diff'             => round(array_sum(array_column($result, 'diff_value')), 2),
            ],
        ];
    }

    public function exportMatrix(Request $request)
    {
        return app(ReportExportService::class)->exportMatrixExcel(
            $request->user()->current_client_id, $request->month ?? now()->format('Y-m')
        );
    }

    public function exportMatrixPdf(Request $request)
    {
        return app(ReportExportService::class)->exportMatrixPdf(
            $request->user()->current_client_id, $request->month ?? now()->format('Y-m')
        );
    }

    public function exportFinancialPdf(Request $request)
    {
        return app(ReportExportService::class)->exportFinancialPdf(
            $request->user()->current_client_id, $request->month ?? now()->format('Y-m')
        );
    }

    /**
     * تقرير وارد المخزن اليومي (جريد تواريخ)
     * كل صف = صنف، كل عمود = يوم الشهر، الخلية = الكمية
     */
    public function warehouseDaily(Request $request): JsonResponse
    {
        $clientId    = $request->user()->current_client_id;
        $warehouseId = $request->warehouse_id;
        $month       = $request->month ?? now()->format('Y-m');
        $start       = Carbon::parse($month . '-01');
        $end         = $start->copy()->endOfMonth();
        $daysInMonth = $start->daysInMonth;

        $items = Item::where('client_id', $clientId)
            ->where('is_active', true)
            ->orderBy('sort_order')->orderBy('name')
            ->get(['id', 'name', 'unit']);

        // نجيب الحركات من stock_ledger + dispatch_orders (عشان تضبط مع القديم والجديد)
        // بنستخدم withoutGlobalScope عشان HasTenant بتضيف where('client_id') بدون table prefix
        $lines = StockLedger::withoutGlobalScope('client')
            ->where('stock_ledger.client_id', $clientId)
            ->where('stock_ledger.warehouse_id', $warehouseId)
            ->where('stock_ledger.movement_type', 'in')
            ->whereBetween('stock_ledger.date', [$start->toDateString(), $end->toDateString()])
            ->join('dispatch_orders', function ($j) {
                $j->on('stock_ledger.ref_id', '=', 'dispatch_orders.id')
                  ->where('stock_ledger.ref_type', '=', 'dispatch_order');
            })
            ->where('dispatch_orders.type', 'purchase')
            ->get(['stock_ledger.item_id', 'stock_ledger.date', 'stock_ledger.qty']);

        $perItem = $lines->groupBy('item_id');
        $grid = [];
        foreach ($items as $item) {
            $days = array_fill(1, $daysInMonth, 0.0);
            foreach ($perItem->get($item->id, collect()) as $line) {
                $day = Carbon::parse($line->date)->day;
                $days[$day] += (float) $line->qty;
            }
            $grid[] = [
                'item_id'   => $item->id,
                'item_name' => $item->name,
                'unit'      => $item->unit,
                'days'      => $days,
                'total'     => round(array_sum($days), 3),
            ];
        }

        return response()->json([
            'month'         => $month,
            'days_in_month' => $daysInMonth,
            'warehouse_id'  => $warehouseId,
            'items'         => $grid,
        ]);
    }

    /**
     * تقرير وارد الفرع اليومي (جريد تواريخ)
     */
    public function branchDaily(Request $request): JsonResponse
    {
        $clientId = $request->user()->current_client_id;
        $branchId = $request->branch_id;
        $month    = $request->month ?? now()->format('Y-m');
        $start    = Carbon::parse($month . '-01');
        $end      = $start->copy()->endOfMonth();
        $daysInMonth = $start->daysInMonth;

        $branch = Warehouse::find($branchId);
        if (!$branch || $branch->client_id !== $clientId) {
            return response()->json(['message' => 'الفرع غير موجود'], 404);
        }

        $items = Item::where('client_id', $clientId)
            ->where('is_active', true)
            ->orderBy('sort_order')->orderBy('name')
            ->get(['id', 'name', 'unit']);

        $lines = StockLedger::withoutGlobalScope('client')
            ->where('stock_ledger.client_id', $clientId)
            ->where('stock_ledger.warehouse_id', $branchId)
            ->whereIn('stock_ledger.movement_type', ['in', 'transfer_in'])
            ->whereBetween('stock_ledger.date', [$start->toDateString(), $end->toDateString()])
            ->join('dispatch_orders', function ($j) {
                $j->on('stock_ledger.ref_id', '=', 'dispatch_orders.id')
                  ->where('stock_ledger.ref_type', '=', 'dispatch_order');
            })
            ->whereIn('dispatch_orders.type', ['purchase', 'dispatch'])
            ->get(['stock_ledger.item_id', 'stock_ledger.date', 'stock_ledger.qty']);

        $perItem = $lines->groupBy('item_id');
        $grid = [];
        foreach ($items as $item) {
            $days = array_fill(1, $daysInMonth, 0.0);
            foreach ($perItem->get($item->id, collect()) as $line) {
                $day = Carbon::parse($line->date)->day;
                $days[$day] += (float) $line->qty;
            }
            $grid[] = [
                'item_id'   => $item->id,
                'item_name' => $item->name,
                'unit'      => $item->unit,
                'days'      => $days,
                'total'     => round(array_sum($days), 3),
            ];
        }

        return response()->json([
            'month'         => $month,
            'days_in_month' => $daysInMonth,
            'branch_id'     => $branchId,
            'branch_name'   => $branch->name,
            'items'         => $grid,
        ]);
    }
}
