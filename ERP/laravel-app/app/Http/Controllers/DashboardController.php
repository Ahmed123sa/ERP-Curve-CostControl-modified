<?php

namespace App\Http\Controllers;

use App\Models\MonthlyClosing;
use App\Models\StockLedger;
use App\Models\Warehouse;
use App\Services\ReportExportService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function kpis(Request $request): JsonResponse
    {
        $clientId = $request->user()->current_client_id;
        if (!$clientId) {
            return response()->json(['total_purchases' => 0, 'total_dispatched' => 0, 'total_diffs' => 0, 'food_cost_pct' => 0]);
        }
        $month = $request->month ?? now()->format('Y-m');
        $start = now()->parse($month . '-01')->toDateString();
        $end   = now()->parse($month . '-01')->endOfMonth()->toDateString();

        $totalPurchases = (float) StockLedger::where('client_id', $clientId)
            ->whereBetween('date', [$start, $end])
            ->where('voucher_type', 'purchase')->where('movement_type', 'in')
            ->sum('total_cost');

        $totalDispatched = (float) StockLedger::where('client_id', $clientId)
            ->whereBetween('date', [$start, $end])
            ->whereIn('movement_type', ['out', 'transfer_out'])
            ->sum('total_cost');

        $totalDiffs = (float) MonthlyClosing::where('client_id', $clientId)
            ->where('month', $month)->sum('diff_value');

        $foodCostPct = $totalPurchases > 0 ? round(($totalDispatched / $totalPurchases) * 100, 1) : 0;

        return response()->json([
            'total_purchases'  => $totalPurchases,
            'total_dispatched' => $totalDispatched,
            'total_diffs'      => $totalDiffs,
            'food_cost_pct'    => $foodCostPct,
        ]);
    }

    public function monthlyTrend(Request $request): JsonResponse
    {
        $clientId = $request->user()->current_client_id;
        if (!$clientId) return response()->json([]);
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
                    ->whereIn('movement_type', ['out', 'transfer_out'])->sum('total_cost'),
                'diffs'      => (float) MonthlyClosing::where('client_id', $clientId)->where('month', $m)->sum('diff_value'),
            ];
        }
        return response()->json($months);
    }

    public function warehouseSummary(Request $request): JsonResponse
    {
        $clientId = $request->user()->current_client_id;
        if (!$clientId) return response()->json([]);
        $month = $request->month ?? now()->format('Y-m');

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
                'diff'     => (float) ($c->diff ?? 0),
            ];
        }
        return response()->json($result);
    }

    public function export(Request $request)
    {
        return app(ReportExportService::class)->exportDashboard(
            $request->user()->current_client_id, $request->month ?? now()->format('Y-m')
        );
    }
}
