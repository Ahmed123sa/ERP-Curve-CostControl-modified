<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Services\CostCalculationService;
use App\Services\MappingService;
use App\Services\StockLedgerService;
use App\Models\MonthlyClosing;
use App\Models\DispatchOrder;
use App\Models\StockLedger;
use App\Models\Item;
use App\Models\DispatchLine;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Carbon\Carbon;

class InventoryUploadController extends Controller
{
    protected $mappingService;
    protected $ledger;

    public function __construct(MappingService $mappingService, StockLedgerService $ledger, CostCalculationService $calc)
    {
        $this->mappingService = $mappingService;
        $this->ledger = $ledger;
        $this->calc = $calc;
    }

    /**
     * رفع ملف الإكسيل وتحليله ومحاولة ربط الأصناف
     */
    public function parse(Request $request): JsonResponse
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls,csv,txt,ods|max:10240',
        ]);

        $clientId = $request->user()->current_client_id;
        $file = $request->file('file');
        $spreadsheet = IOFactory::load($file->getRealPath());
        $sheet = $spreadsheet->getActiveSheet();
        $rows = $sheet->toArray();

        $parsedItems = [];
        // أول صف هو العنوان (Name, Qty, [Cost])
        foreach ($rows as $index => $row) {
            if ($index === 0) continue; 
            
            $sourceName = trim($row[0] ?? '');
            $qty = (float) ($row[1] ?? 0);

            if (empty($sourceName)) continue;

            $mapping = $this->mappingService->findItem($clientId, $sourceName);

            // قراءة التكلفة من العمود الثالث (اختياري) -> لو فاضي نجيب default_cost
            $cost = (float) ($row[2] ?? 0);
            if ($cost <= 0 && $mapping['item_id']) {
                $item = Item::find($mapping['item_id']);
                $cost = (float) ($item->default_cost ?? 0);
            }

            $parsedItems[] = [
                'source_name'  => $sourceName,
                'qty'          => $qty,
                'cost'         => $cost,
                'item_id'      => $mapping['item_id'],
                'item_name'    => $mapping['item_name'],
                'confidence'   => $mapping['confidence'],
                'needs_review' => $mapping['needs_review'],
            ];
        }

        return response()->json([
            'items' => $parsedItems,
            'summary' => [
                'total_rows' => count($parsedItems),
                'needs_review' => count(array_filter($parsedItems, fn($i) => $i['needs_review'])),
            ]
        ]);
    }

    /**
     * تأكيد الحفظ (سواء كأرصدة افتتاحية أو جرد نهائي)
     */
    public function confirm(Request $request): JsonResponse
    {
        $request->validate([
            'warehouse_id' => 'required|uuid',
            'month'        => 'required|date_format:Y-m',
            'type'         => 'required|in:opening,final',
            'items'        => 'required|array',
            'items.*.item_id' => 'required|uuid',
            'items.*.qty'     => 'required|numeric|min:0',
            'items.*.cost'    => 'nullable|numeric|min:0',
        ]);

        $clientId = $request->user()->current_client_id;
        $userId   = $request->user()->id;
        $type     = $request->type;
        $month    = $request->month;
        $warehouseId = $request->warehouse_id;

        if ($type === 'final') {
            $locked = \App\Models\MonthlyClosing::where('client_id', $clientId)
                ->where('warehouse_id', $warehouseId)
                ->where('month', $month)
                ->where('is_locked', true)
                ->exists();
            if ($locked) {
                abort(403, 'الشهر مقفول — لا يمكن رفع ملف إكسيل للجرد');
            }
        }

        DB::transaction(function () use ($request, $clientId, $userId, $type, $month, $warehouseId) {
            
            if ($type === 'opening') {
                // 1. مسح أي أرصدة افتتاحية قديمة لنفس الشهر والمخزن (Replacement Logic)
                $oldVouchers = DispatchOrder::where('client_id', $clientId)
                    ->where('warehouse_id', $warehouseId)
                    ->where('type', 'opening')
                    ->where('date', $month . '-01')
                    ->get();

                foreach ($oldVouchers as $v) {
                    StockLedger::where('ref_type', 'dispatch_order')->where('ref_id', $v->id)->delete();
                    DispatchLine::where('order_id', $v->id)->delete();
                    $v->delete();
                }

                // 2. إنشاء إذن افتتاح جديد بالبيانات المرفوعة
                $order = DispatchOrder::create([
                    'client_id'    => $clientId,
                    'created_by'   => $userId,
                    'warehouse_id' => $warehouseId,
                    'type'         => 'opening',
                    'date'         => $month . '-01',
                    'status'       => 'confirmed',
                    'notes'        => 'رفع إكسيل أرصدة افتتاحية',
                ]);

                foreach ($request->items as $itemData) {
                    // استخدم التكلفة من الملف أو من default_cost
                    $cost = (float) ($itemData['cost'] ?? 0);
                    if ($cost <= 0) {
                        $item = Item::find($itemData['item_id']);
                        $cost = (float) ($item->default_cost ?? 0);
                    }

                    $order->lines()->create([
                        'item_id'      => $itemData['item_id'],
                        'warehouse_id' => $warehouseId,
                        'client_id'    => $clientId,
                        'qty'          => $itemData['qty'],
                        'unit_cost'    => $cost,
                        'total_cost'   => round($itemData['qty'] * $cost, 2),
                    ]);

                    $this->ledger->post(
                        $clientId,
                        $warehouseId,
                        $itemData['item_id'],
                        $month . '-01',
                        'in',
                        (float) $itemData['qty'],
                        round($itemData['qty'] * $cost, 2),
                        $cost,
                        'dispatch_order',
                        $order->id,
                        'opening'
                    );
                }
            } else {
                // نوع final -> جرد آخر المدة
                // 1. إنشاء إذن للتاريخ (Voucher) — بدون StockLedger لأن الجرد مش حركة مخزنية
                $order = DispatchOrder::create([
                    'client_id'    => $clientId,
                    'created_by'   => $userId,
                    'warehouse_id' => $warehouseId,
                    'type'         => 'closing',
                    'date'         => $month . '-01',
                    'status'       => 'confirmed',
                    'notes'        => 'رفع إكسيل جرد آخر المدة',
                ]);

                foreach ($request->items as $itemData) {
                    $order->lines()->create([
                        'item_id'      => $itemData['item_id'],
                        'warehouse_id' => $warehouseId,
                        'client_id'    => $clientId,
                        'qty'          => $itemData['qty'],
                        'unit_cost'    => 0,
                        'total_cost'   => 0,
                    ]);

                    MonthlyClosing::updateOrCreate(
                        [
                            'client_id'    => $clientId,
                            'warehouse_id' => $warehouseId,
                            'item_id'      => $itemData['item_id'],
                            'month'        => $month,
                        ],
                        [
                            'physical_count' => $itemData['qty']
                        ]
                    );
                }
            }
        });

        // auto-sync + regenerate بعد الحفظ خارج الـ transaction
        if ($type === 'final') {
            MonthlyClosing::where('client_id', $clientId)
                ->where('warehouse_id', $warehouseId)
                ->where('month', $month)
                ->whereNotNull('physical_count')
                ->chunkById(100, function ($closings) {
                    foreach ($closings as $c) {
                        $c->closing_qty_actual = $c->physical_count;
                        $c->diff_qty   = round($c->closing_qty_theoretical - $c->closing_qty_actual, 3);
                        $c->diff_value = round($c->diff_qty * $c->avg_cost, 2);
                        $c->save();
                    }
                });
            $this->calc->generateMonthlyClosing($clientId, $warehouseId, $month);
        }

        if ($type === 'opening') {
            $this->calc->generateMonthlyClosing($clientId, $warehouseId, $month);
        }

        return response()->json(['message' => 'تم حفظ البيانات بنجاح من ملف الإكسيل']);
    }
}
