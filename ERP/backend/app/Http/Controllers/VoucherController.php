<?php

namespace App\Http\Controllers;

use App\Services\VoucherParserService;
use App\Services\MappingService;
use App\Services\StockLedgerService;
use App\Models\DispatchOrder;
use App\Models\DispatchLine;
use App\Models\MonthlyClosing;
use App\Models\StockLedger;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * VoucherController
 * إدارة الأذون — رفع Excel أو إدخال يدوي
 */
class VoucherController extends Controller
{
    public function __construct(
        private VoucherParserService  $parser,
        private MappingService        $mapper,
        private StockLedgerService    $ledger,
    ) {}

    // ── رفع Excel وتحليله (Preview قبل الحفظ) ───────────────

    /**
     * POST /api/vouchers/upload
     * يرفع ملف Excel ويرجع البيانات المحللة مع حالة الربط
     * بدون ما يحفظ في الـ DB — المستخدم بيراجع وبيأكد
     */
    public function upload(Request $request): JsonResponse
    {
        $request->validate(['file' => 'required|file|mimes:xlsx,xls|max:10240']);

        $clientId = $request->user()->current_client_id;
        $file     = $request->file('file');
        $path     = $file->store("vouchers/{$clientId}/tmp");

        $parsed = $this->parser->parse(Storage::path($path));

        $preview = [];
        foreach ($parsed['vouchers'] as $voucher) {
            // ابحث عن الموقع (مخزن أو فرع)
            $location = $this->mapper->findLocation($clientId, $voucher['location']);
            $type     = $this->parser->detectVoucherType($voucher['location']);

            $lines = [];
            foreach ($voucher['items'] as $item) {
                $match = $this->mapper->findItem($clientId, $item['name'], $voucher['location']);
                $lines[] = [
                    'source_name' => $item['name'],
                    'unit'        => $item['unit'],
                    'qty'         => $item['qty'],
                    'cost'        => $item['cost'],
                    'unit_cost'   => $item['unit_cost'],
                    // نتيجة الـ mapping
                    'item_id'      => $match['item_id'],
                    'item_name'    => $match['item_name'],
                    'confidence'   => $match['confidence'],
                    'needs_review' => $match['needs_review'],
                ];
            }

            $preview[] = [
                'date'          => $voucher['date'],
                'location_raw'  => $voucher['location'],
                'type'          => $type,
                'location'      => $location,
                'lines'         => $lines,
                'has_issues'    => collect($lines)->where('needs_review', true)->isNotEmpty()
                                   || $location['needs_review'],
            ];
        }

        // احتفظ بالملف المؤقت للتأكيد بعدين
        return response()->json([
            'tmp_path' => $path,
            'vouchers' => $preview,
            'errors'   => $parsed['errors'],
        ]);
    }

    /**
     * POST /api/vouchers/confirm
     * بعد مراجعة المستخدم — حفظ الأذون في الـ DB
     */
    public function confirm(Request $request): JsonResponse
    {
        $request->validate([
            'vouchers'                              => 'required|array',
            'vouchers.*.date'                       => 'required|date',
            'vouchers.*.type'                       => 'required|string',
            'vouchers.*.warehouse_id'               => 'nullable|uuid',
            'vouchers.*.branch_id'                  => 'nullable|uuid',
            'vouchers.*.lines'                      => 'required|array',
            'vouchers.*.lines.*.item_id'            => 'required|uuid',
            'vouchers.*.lines.*.warehouse_id'       => 'required|uuid',
            'vouchers.*.lines.*.qty'                => 'required|numeric',
            'vouchers.*.lines.*.cost'               => 'nullable|numeric|min:0',
        ]);

        $clientId = $request->user()->current_client_id;
        $userId   = $request->user()->id;
        $saved    = [];
        $skipped  = [];

        DB::transaction(function () use ($request, $clientId, $userId, &$saved, &$skipped) {
            foreach ($request->vouchers as $voucherIndex => $voucherData) {
                $validLines = [];
                foreach (($voucherData['lines'] ?? []) as $lineIndex => $line) {
                    $qty = (float) ($line['qty'] ?? 0);
                    if ($qty < 0.001) {
                        $skipped[] = [
                            'voucher_index' => $voucherIndex,
                            'line_index'    => $lineIndex,
                            'item_id'       => $line['item_id'] ?? null,
                            'source_name'   => $line['source_name'] ?? null,
                            'reason'        => 'qty أقل من 0.001',
                            'qty'           => $line['qty'] ?? null,
                        ];
                        continue;
                    }

                    $validLines[] = $line;
                }

                // لو كل سطور الإذن كميتها صفر/فارغة نتخطى الإذن بالكامل بدل فشل العملية كلها
                if (empty($validLines)) {
                    $skipped[] = [
                        'voucher_index' => $voucherIndex,
                        'reason'        => 'تم تخطي الإذن بالكامل: لا يوجد سطور صالحة للحفظ',
                    ];
                    continue;
                }

                $order = DispatchOrder::create([
                    'client_id'    => $clientId,
                    'type'         => $voucherData['type'],
                    'date'         => $voucherData['date'],
                    'warehouse_id' => $voucherData['warehouse_id'] ?? null,
                    'branch_id'    => $voucherData['branch_id'] ?? null,
                    'created_by'   => $userId,
                    'status'       => 'confirmed',
                    'source_file'  => $voucherData['source_file'] ?? null,
                ]);

                foreach ($validLines as $line) {
                    $qty      = (float) $line['qty'];
                    $cost     = (float) ($line['cost'] ?? 0);
                    $unitCost = $qty > 0 && $cost > 0 ? round($cost / $qty, 4) : 0;

                    DispatchLine::create([
                        'order_id'     => $order->id,
                        'item_id'      => $line['item_id'],
                        'warehouse_id' => $line['warehouse_id'],
                        'qty'          => $qty,
                        'total_cost'   => $cost,
                        'unit_cost'    => $unitCost,
                    ]);

                    // تسجيل في الـ Stock Ledger
                    $this->ledger->post(
                        clientId:  $clientId,
                        whId:      $line['warehouse_id'],
                        itemId:    $line['item_id'],
                        date:      $voucherData['date'],
                        type:      $voucherData['type'],
                        qty:       $qty,
                        totalCost: $cost,
                        unitCost:  $unitCost,
                        refType:   'dispatch_order',
                        refId:     $order->id,
                    );

                    // حفظ الـ mapping عشان المرة الجاية
                    if (!empty($line['source_name'])) {
                        $this->mapper->saveItemMapping(
                            $clientId,
                            $line['source_name'],
                            $line['item_id'],
                            $voucherData['location_raw'] ?? null
                        );
                    }
                }

                $saved[] = $order->id;
            }
        });

        if (empty($saved)) {
            return response()->json([
                'message' => 'لم يتم حفظ أي إذن: كل السطور كانت بكميات أقل من 0.001',
                'skipped' => $skipped,
            ], 422);
        }

        return response()->json([
            'message' => 'تم حفظ الأذون بنجاح',
            'order_ids' => $saved,
            'skipped' => $skipped,
        ]);
    }

    // ── إدخال يدوي (Grid) ────────────────────────────────────

    /**
     * POST /api/vouchers/manual
     * حفظ إذن مدخول يدوياً من الـ Grid
     */
    public function manual(Request $request): JsonResponse
    {
        $request->validate([
            'type'         => 'required|in:purchase,dispatch,transfer,withdrawal',
            'date'         => 'required|date',
            'warehouse_id' => 'nullable|uuid',
            'branch_id'    => 'nullable|uuid',
            'lines'        => 'required|array|min:1',
            'lines.*.item_id'      => 'required|uuid',
            'lines.*.warehouse_id' => 'required|uuid',
            'lines.*.qty'          => 'required|numeric|min:0.001',
            'lines.*.cost'         => 'nullable|numeric|min:0',
        ]);

        $clientId = $request->user()->current_client_id;
        $userId   = $request->user()->id;

        $order = DB::transaction(function () use ($request, $clientId, $userId) {
            $order = DispatchOrder::create([
                'client_id'    => $clientId,
                'type'         => $request->type,
                'date'         => $request->date,
                'warehouse_id' => $request->warehouse_id,
                'branch_id'    => $request->branch_id,
                'created_by'   => $userId,
                'status'       => 'confirmed',
            ]);

            foreach ($request->lines as $line) {
                $qty      = (float) $line['qty'];
                $cost     = (float) ($line['cost'] ?? 0);
                $unitCost = $qty > 0 && $cost > 0 ? round($cost / $qty, 4) : 0;

                DispatchLine::create([
                    'order_id'     => $order->id,
                    'item_id'      => $line['item_id'],
                    'warehouse_id' => $line['warehouse_id'],
                    'qty'          => $qty,
                    'total_cost'   => $cost,
                    'unit_cost'    => $unitCost,
                ]);

                $this->ledger->post(
                    clientId:  $clientId,
                    whId:      $line['warehouse_id'],
                    itemId:    $line['item_id'],
                    date:      $request->date,
                    type:      $request->type,
                    qty:       $qty,
                    totalCost: $cost,
                    unitCost:  $unitCost,
                    refType:   'dispatch_order',
                    refId:     $order->id,
                );
            }

            return $order;
        });

        return response()->json(['message' => 'تم الحفظ', 'order_id' => $order->id], 201);
    }

    // ── قائمة الأذون ─────────────────────────────────────────

    public function index(Request $request): JsonResponse
    {
        $clientId = $request->user()->current_client_id;

        $orders = DispatchOrder::where('client_id', $clientId)
            ->with(['lines.item', 'lines.warehouse', 'branch', 'warehouse'])
            ->when($request->month, fn($q) => $q->whereRaw("to_char(date, 'YYYY-MM') = ?", [$request->month]))
            ->when($request->type, fn($q) => $q->where('type', $request->type))
            ->orderByDesc('date')
            ->paginate(50);

        return response()->json($orders);
    }

    // ── رفع الجرد (Inventory) ──────────────────────────────

    /**
     * POST /api/inventory/parse
     * تحليل ملف Excel للجرد
     */
    public function parseInventory(Request $request): JsonResponse
    {
        $request->validate(['file' => 'required|file|mimes:xlsx,xls,csv|max:10240']);

        $clientId = $request->user()->current_client_id;
        $file     = $request->file('file');
        $path     = $file->store("inventory/{$clientId}/tmp");

        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load(Storage::path($path));
        $rows        = $spreadsheet->getActiveSheet()->toArray(null, true, true, false);

        // Skip header row, parse items
        $parsedItems = [];
        foreach ($rows as $row) {
            $sourceName = trim((string) ($row[0] ?? ''));
            $qty        = (float) ($row[1] ?? 0);

            if (empty($sourceName)) continue;
            if ($qty === 0.0 && count($parsedItems) > 0) continue; // skip trailing empty

            // Find existing mapping
            $match = $this->mapper->findItem($clientId, $sourceName);

            $parsedItems[] = [
                'source_name'  => $sourceName,
                'item_id'      => $match['item_id'] ?? null,
                'item_name'    => $match['item_name'] ?? $sourceName,
                'qty'          => $qty,
                'needs_review' => $match['needs_review'] ?? false,
                'confidence'   => $match['confidence'] ?? 0,
            ];
        }

        return response()->json([
            'items'   => $parsedItems,
            'summary' => ['total_rows' => count($parsedItems)],
        ]);
    }

    /**
     * POST /api/inventory/confirm
     * تأكيد وحفظ الجرد (للأرصدة الافتتاحية أو الجرد النهائي)
     */
    public function confirmInventory(Request $request): JsonResponse
    {
        $request->validate([
            'warehouse_id'         => 'required|uuid',
            'month'                => 'required|date_format:Y-m',
            'type'                 => 'required|in:opening,final',
            'items'                => 'required|array|min:1',
            'items.*.item_id'      => 'required|uuid',
            'items.*.qty'          => 'required|numeric|min:0',
        ]);

        $clientId    = $request->user()->current_client_id;
        $warehouseId = $request->warehouse_id;
        $month       = $request->month;
        $type        = $request->type;
        $userId      = $request->user()->id;

        DB::transaction(function () use ($request, $clientId, $warehouseId, $month, $type, $userId) {
            if ($type === 'opening') {
                $date = $month . '-01';
                $now  = now();
                $rows = [];

                foreach ($request->items as $item) {
                    $qty = (float) $item['qty'];
                    if ($qty <= 0) continue;

                    $rows[] = [
                        'client_id'     => $clientId,
                        'warehouse_id'  => $warehouseId,
                        'item_id'       => $item['item_id'],
                        'date'          => $date,
                        'movement_type' => 'in',
                        'qty'           => $qty,
                        'unit_cost'     => 0,
                        'total_cost'    => 0,
                        'ref_type'      => 'opening_balance',
                        'ref_id'        => \Illuminate\Support\Str::uuid(),
                        'created_at'    => $now,
                        'updated_at'    => $now,
                    ];
                }

                if (!empty($rows)) {
                    StockLedger::insert($rows);
                }
            } else {
                // Final type: update actual closing quantities
                foreach ($request->items as $item) {
                    $closing = MonthlyClosing::where('client_id', $clientId)
                        ->where('warehouse_id', $warehouseId)
                        ->where('month', $month)
                        ->where('item_id', $item['item_id'])
                        ->first();

                    if ($closing && !$closing->is_locked) {
                        $actual = (float) $item['qty'];
                        $closing->closing_qty_actual = $actual;
                        $closing->diff_qty           = round($closing->closing_qty_theoretical - $actual, 3);
                        $closing->diff_value         = round($closing->diff_qty * $closing->avg_cost, 2);
                        $closing->save();
                    }
                }
            }
        });

        return response()->json(['message' => 'تم حفظ البيانات بنجاح ✓']);
    }
}
