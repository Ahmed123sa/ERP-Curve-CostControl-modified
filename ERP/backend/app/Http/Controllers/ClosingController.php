<?php

namespace App\Http\Controllers;

use App\Services\CostCalculationService;
use App\Models\MonthlyClosing;
use App\Models\Warehouse;
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

        $rows = MonthlyClosing::where('client_id', $clientId)
            ->where('warehouse_id', $warehouseId)
            ->where('month', $month)
            ->with('item:id,name,unit')
            ->orderBy('id')
            ->get()
            ->map(fn($r) => [
                'id'                       => $r->id,
                'item_name'                => $r->item->name,
                'unit'                     => $r->item->unit,
                'opening_qty'              => $r->opening_qty,
                'opening_value'            => $r->opening_value,
                'in_qty'                   => $r->in_qty,
                'in_value'                 => $r->in_value,
                'out_qty'                  => $r->out_qty,
                'avg_cost'                 => $r->avg_cost,
                'closing_qty_theoretical'  => $r->closing_qty_theoretical,
                'closing_qty_actual'       => $r->closing_qty_actual,
                'closing_value'            => $r->closing_value,
                'diff_qty'                 => $r->diff_qty,
                'diff_value'               => $r->diff_value,
                'is_locked'                => $r->is_locked,
            ]);

        return response()->json(['data' => $rows]);
    }

    public function generate(Request $request): JsonResponse
    {
        $request->validate([
            'warehouse_id' => 'required|uuid',
            'month'        => 'required|date_format:Y-m',
        ]);

        $clientId = $request->user()->current_client_id;
        $results  = $this->calc->generateMonthlyClosing(
            $clientId,
            $request->warehouse_id,
            $request->month
        );

        return response()->json(['message' => 'تم توليد التقفيل', 'count' => count($results)]);
    }

    public function updateActual(Request $request, MonthlyClosing $closing): JsonResponse
    {
        abort_if($closing->is_locked, 403, 'الشهر مقفول');

        $request->validate(['closing_qty_actual' => 'required|numeric|min:0']);

        $actual = (float) $request->closing_qty_actual;

        $closing->closing_qty_actual = $actual;
        $closing->diff_qty           = round($closing->closing_qty_theoretical - $actual, 3);
        $closing->diff_value         = round($closing->diff_qty * $closing->avg_cost, 2);
        $closing->save();

        return response()->json(['message' => 'تم التحديث', 'diff_qty' => $closing->diff_qty, 'diff_value' => $closing->diff_value]);
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
        $wh          = Warehouse::find($warehouseId);

        $rows = MonthlyClosing::where('client_id', $clientId)
            ->where('warehouse_id', $warehouseId)
            ->where('month', $month)
            ->with('item')
            ->get();

        // بناء ملف Excel بنفس شكل الشيت
        $data = [
            ['تقفيل خامات — ' . ($wh->name ?? '') . ' — ' . $month],
            [],
            ['الصنف','الوحدة','أول المدة (كمية)','قيمة أول المدة','الوارد (كمية)','قيمة الوارد','المنصرف','متوسط السعر','نظري آخر المدة','فعلي آخر المدة','الفرق كمية','قيمة الفرق'],
        ];

        foreach ($rows as $r) {
            $data[] = [
                $r->item->name,
                $r->item->unit,
                $r->opening_qty,
                $r->opening_value,
                $r->in_qty,
                $r->in_value,
                $r->out_qty,
                $r->avg_cost,
                $r->closing_qty_theoretical,
                $r->closing_qty_actual ?? '',
                $r->diff_qty,
                $r->diff_value,
            ];
        }

        $filename = "تقفيل_{$month}_{$wh->name}.xlsx";

        return response()->streamDownload(function () use ($data) {
            $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();
            foreach ($data as $ri => $row) {
                foreach ($row as $ci => $val) {
                    $sheet->setCellValueByColumnAndRow($ci + 1, $ri + 1, $val);
                }
            }
            $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
            $writer->save('php://output');
        }, $filename, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }
}
