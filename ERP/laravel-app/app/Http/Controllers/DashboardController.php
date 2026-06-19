<?php

namespace App\Http\Controllers;

use App\Models\MonthlyClosing;
use App\Models\StockLedger;
use App\Models\Warehouse;
use App\Services\MenuEngineering\SmartAnalyticsService;
use App\Services\ReportExportService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function kpis(Request $request): JsonResponse
    {
        $clientId = $request->user()->current_client_id;
        if (!$clientId) {
            return response()->json(['total_purchases' => 0, 'total_dispatched' => 0, 'total_diffs' => 0]);
        }
        $month = $request->month ?? now()->format('Y-m');

        return Cache::remember("dash_kpis:{$clientId}:{$month}", 300, function () use ($clientId, $month) {
            $start = now()->parse($month . '-01')->toDateString();
            $end   = now()->parse($month . '-01')->endOfMonth()->toDateString();

            $warehouseIds = Warehouse::where('client_id', $clientId)
                ->whereIn('type', ['main', 'sub'])->pluck('id');

            $totalPurchases = (float) StockLedger::where('client_id', $clientId)
                ->whereBetween('date', [$start, $end])
                ->where('voucher_type', 'purchase')->where('movement_type', 'in')
                ->sum('total_cost');

            $totalDispatched = (float) StockLedger::where('client_id', $clientId)
                ->whereBetween('date', [$start, $end])
                ->where('voucher_type', 'dispatch')->where('movement_type', 'out')
                ->whereIn('warehouse_id', $warehouseIds)
                ->sum('total_cost');

            $totalDiffs = (float) MonthlyClosing::where('client_id', $clientId)
                ->whereIn('warehouse_id', $warehouseIds)
                ->where('month', $month)->sum('diff_value');

            $prevMonth = now()->parse($month)->subMonth()->format('Y-m');
            $prevDiffs = (float) MonthlyClosing::where('client_id', $clientId)
                ->whereIn('warehouse_id', $warehouseIds)
                ->where('month', $prevMonth)->sum('diff_value');
            $prevPurchases = (float) StockLedger::where('client_id', $clientId)
                ->whereBetween('date', [now()->parse($prevMonth . '-01')->toDateString(), now()->parse($prevMonth . '-01')->endOfMonth()->toDateString()])
                ->where('voucher_type', 'purchase')->where('movement_type', 'in')
                ->sum('total_cost');
            $prevDispatched = (float) StockLedger::where('client_id', $clientId)
                ->whereBetween('date', [now()->parse($prevMonth . '-01')->toDateString(), now()->parse($prevMonth . '-01')->endOfMonth()->toDateString()])
                ->where('voucher_type', 'dispatch')->where('movement_type', 'out')
                ->whereIn('warehouse_id', $warehouseIds)
                ->sum('total_cost');

            $branchIds = Warehouse::where('client_id', $clientId)
                ->where('type', 'branch')->pluck('id');
            $totalStockValueWh = (float) MonthlyClosing::where('client_id', $clientId)
                ->whereIn('warehouse_id', $warehouseIds)
                ->where('month', $month)
                ->selectRaw('SUM(COALESCE(closing_qty_actual, 0) * avg_cost) as stock_value')
                ->value('stock_value');
            $totalStockValueBr = (float) MonthlyClosing::where('client_id', $clientId)
                ->whereIn('warehouse_id', $branchIds)
                ->where('month', $month)
                ->selectRaw('SUM(COALESCE(closing_qty_actual, 0) * avg_cost) as stock_value')
                ->value('stock_value');

            return response()->json([
                'total_purchases'  => $totalPurchases,
                'total_dispatched' => $totalDispatched,
                'total_diffs'      => $totalDiffs,
                'total_stock_value_warehouses' => $totalStockValueWh,
                'total_stock_value_branches'   => $totalStockValueBr,

                'purchases_change'  => $prevPurchases > 0 ? round((($totalPurchases - $prevPurchases) / $prevPurchases) * 100, 1) : 0,
                'dispatched_change' => $prevDispatched > 0 ? round((($totalDispatched - $prevDispatched) / $prevDispatched) * 100, 1) : 0,
                'diffs_change'      => $prevDiffs != 0 ? round((($totalDiffs - $prevDiffs) / abs($prevDiffs)) * 100, 1) : 0,
            ]);
        });
    }

    public function monthlyTrend(Request $request): JsonResponse
    {
        $clientId = $request->user()->current_client_id;
        if (!$clientId) return response()->json([]);

        return Cache::remember("dash_trend:{$clientId}", 600, function () use ($clientId) {
            $warehouseIds = Warehouse::where('client_id', $clientId)
                ->whereIn('type', ['main', 'sub'])->pluck('id');
            $months = [];
            for ($i = 5; $i >= 0; $i--) {
                $m = now()->subMonths($i)->format('Y-m');
                $start = now()->parse($m . '-01')->toDateString();
                $end   = now()->parse($m . '-01')->endOfMonth()->toDateString();
                $months[] = [
                    'month'      => $m,
                    'purchases'  => (float) StockLedger::where('client_id', $clientId)->whereBetween('date', [$start, $end])
                        ->where('voucher_type', 'purchase')->where('movement_type', 'in')->sum('total_cost'),
                    'dispatched' => (float) StockLedger::where('client_id', $clientId)->whereBetween('date', [$start, $end])
                        ->where('voucher_type', 'dispatch')->where('movement_type', 'out')
                        ->whereIn('warehouse_id', $warehouseIds)->sum('total_cost'),
                    'diffs'      => (float) MonthlyClosing::where('client_id', $clientId)
                        ->whereIn('warehouse_id', $warehouseIds)->where('month', $m)->sum('diff_value'),
                ];
            }
            return response()->json($months);
        });
    }

    public function warehouseSummary(Request $request): JsonResponse
    {
        $clientId = $request->user()->current_client_id;
        if (!$clientId) return response()->json([]);
        $month = $request->month ?? now()->format('Y-m');

        return Cache::remember("dash_wh_summary:{$clientId}:{$month}", 300, function () use ($clientId, $month) {
            $warehouses = Warehouse::where('client_id', $clientId)->where('is_active', true)->get(['id', 'name', 'type']);

            $closings = MonthlyClosing::where('client_id', $clientId)
                ->where('month', $month)
                ->selectRaw('warehouse_id, SUM(opening_value) as opening, SUM(purchases_value) as purchases, SUM(in_value) as total_in, SUM(out_qty) as out_qty, SUM(diff_value) as diff')
                ->groupBy('warehouse_id')
                ->get()
                ->keyBy('warehouse_id');

            $result = [];
            foreach ($warehouses as $wh) {
                $c = $closings->get($wh->id);
                $result[] = [
                    'id'       => $wh->id,
                    'name'     => $wh->name,
                    'type'     => $wh->type,
                    'opening'  => (float) ($c->opening ?? 0),
                    'purchases'=> $wh->type === 'branch' ? '—' : (float) ($c->purchases ?? 0),
                    'in'       => $wh->type === 'branch' ? (float) ($c->total_in ?? 0) : (float) ($c->purchases ?? 0),
                    'out_qty'  => (float) ($c->out_qty ?? 0),
                    'diff'     => $wh->type === 'branch' ? '—' : (float) ($c->diff ?? 0),
                ];
            }
            return response()->json($result);
        });
    }

    public function diffsByWarehouse(Request $request): JsonResponse
    {
        $clientId = $request->user()->current_client_id;
        if (!$clientId) return response()->json([]);
        $month = $request->month ?? now()->format('Y-m');

        return Cache::remember("dash_diffs_wh:{$clientId}:{$month}", 300, function () use ($clientId, $month) {
            $warehouseIds = Warehouse::where('client_id', $clientId)
                ->whereIn('type', ['main', 'sub'])->pluck('id');

            $data = MonthlyClosing::where('client_id', $clientId)
                ->whereIn('warehouse_id', $warehouseIds)
                ->where('month', $month)
                ->selectRaw('warehouse_id, SUM(diff_value) as total_diff')
                ->groupBy('warehouse_id')
                ->get()
                ->map(function ($row) {
                    $wh = Warehouse::find($row->warehouse_id);
                    return [
                        'name'  => $wh?->name ?? 'مخزن',
                        'value' => round(abs((float) $row->total_diff), 2),
                    ];
                })->sortByDesc('value')->values();

            return response()->json($data);
        });
    }

    public function topDiffItems(Request $request): JsonResponse
    {
        $clientId = $request->user()->current_client_id;
        if (!$clientId) return response()->json([]);
        $month = $request->month ?? now()->format('Y-m');

        return Cache::remember("dash_top_diffs:{$clientId}:{$month}", 300, function () use ($clientId, $month) {
            $warehouseIds = Warehouse::where('client_id', $clientId)
                ->whereIn('type', ['main', 'sub'])->pluck('id');

            $data = MonthlyClosing::where('client_id', $clientId)
                ->whereIn('warehouse_id', $warehouseIds)
                ->where('month', $month)
                ->selectRaw('item_id, SUM(diff_value) as total_diff')
                ->groupBy('item_id')
                ->orderByRaw('ABS(SUM(diff_value)) DESC')
                ->limit(10)
                ->get()
                ->map(function ($row) {
                    $item = \App\Models\Item::find($row->item_id);
                    return [
                        'item_name' => $item?->name ?? 'صنف',
                        'diff_value' => round((float) $row->total_diff, 2),
                    ];
                });

            return response()->json($data);
        });
    }

    public function export(Request $request)
    {
        return app(ReportExportService::class)->exportDashboard(
            $request->user()->current_client_id, $request->month ?? now()->format('Y-m')
        );
    }

    public function smartSummary(Request $request): JsonResponse
    {
        $clientId = $request->user()->current_client_id;
        if (!$clientId) {
            return response()->json(['critical_count' => 0, 'stock_value' => 0, 'recent_price_changes' => [], 'monthly_purchase_count' => 0]);
        }
        return response()->json(
            app(SmartAnalyticsService::class)->smartSummary($clientId)
        );
    }
}
