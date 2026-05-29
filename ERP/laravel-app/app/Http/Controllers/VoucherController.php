<?php

namespace App\Http\Controllers;

use App\Http\Requests\VoucherConfirmRequest;
use App\Http\Requests\VoucherManualRequest;
use App\Http\Requests\VoucherUploadRequest;
use App\Services\VoucherParserService;
use App\Services\MappingService;
use App\Services\StockLedgerService;
use App\Services\ActivityLogger;
use App\Services\CostCalculationService;
use App\Models\ActivityLog;
use App\Models\DispatchOrder;
use App\Models\DispatchLine;
use App\Models\Warehouse;
use App\Models\BranchWarehouseSource;
use App\Models\Item;
use App\Models\MonthlyClosing;
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
        private VoucherParserService   $parser,
        private MappingService         $mapper,
        private StockLedgerService     $ledger,
        private CostCalculationService $calc,
    ) {}

    // ── رفع Excel وتحليله (Preview قبل الحفظ) ───────────────

    /**
     * POST /api/vouchers/upload
     * يرفع ملف Excel ويرجع البيانات المحللة مع حالة الربط
     * بدون ما يحفظ في الـ DB — المستخدم بيراجع وبيأكد
     */
    public function upload(VoucherUploadRequest $request): JsonResponse
    {
        $clientId = $request->user()->current_client_id;
        $file     = $request->file('file');
        $path     = $file->store("vouchers/{$clientId}/tmp");

        $parsed = $this->parser->parse(Storage::path($path));

        $preview = [];
        foreach ($parsed['vouchers'] as $voucher) {
            // ابحث عن الموقع (مخزن أو فرع)
            $location = $this->mapper->findLocation($clientId, $voucher['location']);
            $type     = $this->parser->detectVoucherType($voucher['location']);

            // حدد المخزن المصدر حسب نوع الموقع
            $suggestedWarehouseId = null;
            if ($location['type'] === 'warehouse' || $location['type'] === 'main' || $location['type'] === 'sub') {
                // مخزن — هو المصدر لنفسه (للوارد)
                $suggestedWarehouseId = $location['id'];
            } else {
                // فرع — اقترح المخزن الرئيسي كمصدر
                $mainWh = Warehouse::where('client_id', $clientId)->where('type', 'main')->first();
                $suggestedWarehouseId = $mainWh ? $mainWh->id : null;
            }

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
                    'warehouse_id' => $suggestedWarehouseId,
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
            'tmp_path'   => $path,
            'vouchers'   => $preview,
            'warehouses' => Warehouse::where('client_id', $clientId)->where('is_active', true)->get(),
            'errors'     => $parsed['errors'],
        ]);
    }

/**
      * POST /api/vouchers/confirm
      * بعد مراجعة المستخدم — حفظ الأذون في الـ DB
      */
    public function confirm(VoucherConfirmRequest $request): JsonResponse
    {
        $clientId = $request->user()->current_client_id;
        $userId   = $request->user()->id;
        $saved      = [];
        $skipped    = [];
        $priceSkips = [];

        DB::transaction(function () use ($request, $clientId, $userId, &$saved, &$skipped, &$priceSkips) {
            foreach ($request->vouchers as $voucherIndex => $voucherData) {
                // استخراج المخزن أو الفرع من كائن location
                $loc = $voucherData['location'] ?? [];
                $locType = $loc['type'] ?? '';
                $warehouseId = in_array($locType, ['warehouse', 'main', 'sub']) ? ($loc['id'] ?? null) : null;
                $branchId    = $locType === 'branch' ? ($loc['id'] ?? null) : null;

                // fallback من payload (مهم لسيناريو المراجعة اليدوية في الواجهة)
                if (!$warehouseId && !empty($voucherData['warehouse_id'])) {
                    $warehouseId = $voucherData['warehouse_id'];
                }
                if (!$branchId && !empty($voucherData['branch_id'])) {
                    $branchId = $voucherData['branch_id'];
                }

                $orderDate = \Illuminate\Support\Carbon::parse($voucherData['date'])->toDateString();

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

                if (empty($validLines)) {
                    $skipped[] = [
                        'voucher_index' => $voucherIndex,
                        'reason'        => 'تم تخطي الإذن بالكامل: لا يوجد سطور صالحة للحفظ',
                    ];
                    continue;
                }

                // لو opening — نمسح القديم لنفس الموقع والشهر عشان ما يتكررش
                if ($voucherData['type'] === 'opening') {
                    $monthPrefix = substr($orderDate, 0, 7);
                    $oldQ = DispatchOrder::where('client_id', $clientId)
                        ->where('type', 'opening')
                        ->where('date', 'like', $monthPrefix . '%');
                    if ($warehouseId) $oldQ->where('warehouse_id', $warehouseId);
                    elseif ($branchId) $oldQ->where('branch_id', $branchId);
                    $oldIds = $oldQ->pluck('id');
                    if ($oldIds->isNotEmpty()) {
                        \App\Models\StockLedger::whereIn('ref_id', $oldIds)
                            ->where('ref_type', 'dispatch_order')->delete();
                        DispatchLine::whereIn('order_id', $oldIds)->delete();
                        DispatchOrder::whereIn('id', $oldIds)->delete();
                    }
                }

                $order = DispatchOrder::create([
                    'client_id'    => $clientId,
                    'type'         => $voucherData['type'],
                    'date'         => $orderDate,
                    'warehouse_id' => $warehouseId ?: null,
                    'branch_id'    => $branchId ?: null,
                    'created_by'   => $userId,
                    'status'       => 'confirmed',
                    'source'       => 'upload',
                    'source_file'  => $voucherData['source_file'] ?? null,
                ]);

                foreach ($validLines as $line) {
                    $qty      = (float) $line['qty'];
                    $cost     = (float) ($line['cost'] ?? 0);
                    // للتوزيع (dispatch) بدون تكلفة — نحسب تلقائياً من default_cost
                    if ($voucherData['type'] === 'dispatch' && $cost <= 0) {
                        $item = Item::where('id', $line['item_id'])->where('client_id', $clientId)->first();
                        if ($item && $item->default_cost > 0) {
                            $cost = round($qty * $item->default_cost, 2);
                        }
                    }
                    $unitCost = $qty > 0 && $cost > 0 ? round($cost / $qty, 4) : 0;

                    $sourceWhId = null;
                    $destWhId   = null;

                    $movementType = match ($voucherData['type']) {
                        'dispatch'          => null, // handled below with two entries
                        'purchase', 'opening', 'adjustment', 'return' => 'in',
                        'withdrawal', 'external_sale', 'production'   => 'out',
                        'transfer'          => null, // handled via postTransfer
                        default             => 'in',
                    };

                    if ($voucherData['type'] === 'dispatch') {
                        $sourceWhId = $this->resolveWarehouseId($clientId, $loc, $line['item_id']);
                        $destWhId   = $branchId ? $this->resolveBranchTargetWhId($clientId, $branchId) : $warehouseId;

                        if ($sourceWhId) {
                            $this->ledger->post($clientId, $sourceWhId, $line['item_id'], $orderDate, 'out', $qty, $cost, $unitCost, 'dispatch_order', $order->id, $voucherData['type']);
                        }
                        if ($destWhId && $destWhId !== $sourceWhId) {
                            $this->ledger->post($clientId, $destWhId, $line['item_id'], $orderDate, 'in', $qty, $cost, $unitCost, 'dispatch_order', $order->id, $voucherData['type']);
                        }
                    } elseif ($voucherData['type'] === 'transfer') {
                        $sourceWhId = $warehouseId;
                        $destWhId   = ($line['warehouse_id'] ?? null) ?: ($branchId ?? $warehouseId);
                        if ($sourceWhId && $destWhId && $sourceWhId !== $destWhId) {
                            $this->ledger->postTransfer($clientId, $sourceWhId, $destWhId, $line['item_id'], $orderDate, $qty, $unitCost, $order->id);
                        }
                    } else {
                        $whId = ($line['warehouse_id'] ?? null) ?: ($warehouseId ?? $branchId);
                        if ($whId) {
                            $this->ledger->post($clientId, $whId, $line['item_id'], $orderDate, $movementType, $qty, $cost, $unitCost, 'dispatch_order', $order->id, $voucherData['type']);
                        } else {
                            $skipped[] = [
                                'voucher_index' => $voucherIndex,
                                'item_id'       => $line['item_id'] ?? null,
                                'source_name'   => $line['source_name'] ?? null,
                                'reason'        => 'تعذر تحديد المخزن',
                            ];
                        }
                    }

                    // تأمين warehouse_id لضمان عدم حدوث خطأ SQL
                    $finalWhId = $sourceWhId ?? $destWhId;
                    if (!$finalWhId) {
                        $mainWh = Warehouse::where('client_id', $clientId)->where('type', 'main')->first();
                        $finalWhId = $mainWh ? $mainWh->id : null;
                    }

                    DispatchLine::create([
                        'order_id'     => $order->id,
                        'item_id'      => $line['item_id'],
                        'warehouse_id' => $finalWhId,
                        'qty'          => $qty,
                        'total_cost'   => $cost,
                        'unit_cost'    => $unitCost,
                        'date'         => $line['date'] ?? null,
                    ]);

                    // تحديث default_cost بمتوسط السعر المرجح بعد ترحيل الحركة
                    if ($voucherData['type'] === 'purchase' && $unitCost > 0) {
                        $item = Item::where('id', $line['item_id'])
                            ->where('client_id', $clientId)
                            ->first();
                        if ($item) {
                            $oldCost = $item->default_cost;
                            $calc = app(\App\Services\CostCalculationService::class);
                            $whId = ($line['warehouse_id'] ?? null) ?: ($warehouseId ?? $branchId);
                            if (!$whId) {
                                $wh = Warehouse::where('client_id', $clientId)->where('type', 'main')->first();
                                $whId = $wh ? $wh->id : null;
                            }
                            $avgCost = $whId ? $calc->weightedAverageCost($clientId, $whId, $item->id) : 0;
                            $newCost = $avgCost > 0 ? $avgCost : $unitCost;
                            $item->default_cost = $newCost;
                            $item->save();
                            if ((float) $oldCost !== $newCost) {
                                ActivityLogger::log(
                                    action:     'price_updated',
                                    entityType: 'Item',
                                    entityId:   $item->id,
                                    oldValues:  ['default_cost' => $oldCost],
                                    newValues:  ['default_cost' => $newCost, 'avg_cost' => $avgCost, 'unit_cost' => $unitCost, 'source' => 'purchase_voucher', 'voucher_id' => $order->id],
                                );
                            }
                        }
                    }

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
            'price_skipped' => $priceSkips,
        ]);
    }

    /**
     * حدد الـ warehouse_id الصحيح في stock_ledger عند التوزيع لفرع
     * (لأن branch_id من branches table قد لا يطابق معرف السجل في warehouses)
     */
    private function resolveBranchTargetWhId(string $clientId, string $branchId): string
    {
        // 1. لو المعرف نفسه موجود في جدول warehouses (مزامنة سابقة) — استخدمه مباشرة
        if (Warehouse::where('id', $branchId)->exists()) {
            return $branchId;
        }
        // 2. ابحث عن مخزن بنفس الاسم ونوعه branch
        $branch = \App\Models\Branch::find($branchId);
        if ($branch) {
            $wh = Warehouse::where('client_id', $clientId)
                ->where('name', $branch->name)
                ->where('type', 'branch')
                ->first();
            if ($wh) {
                return $wh->id;
            }
        }
        // 3. Fallback — استخدم المعرف كما هو
        return $branchId;
    }

    /**
     * حدد أي مخزن يخدم كـ source لفرع معين وصنف معين
     * 1. مخزن مخصص للصنف في الفرع (item_id)
     * 2. مخزن افتراضي للفرع (كل الأصناف)
     * 3. fallback للمخزن الرئيسي
     */
    private function resolveWarehouseId(string $clientId, array $location, ?string $itemId): ?string
    {
        $locationId   = $location['id'] ?? null;
        $locationType = $location['type'] ?? '';

        // 1. لو الموقع هو "مخزن" (رئيسي أو فرعي) -> هو المصدر لنفسه (في حالة المشتريات مثلاً)
        if ($locationType === 'main' || $locationType === 'sub') {
            return $locationId;
        }

        // 2. لو إذن صرف لفرع -> لازم نحل أي مخزن هو المورد لهذا الصنف
        if (!$locationId) return null;

        // أ) بحث عن ربط صريح للصنف ده في الموقع ده (Branch-Item Mapping)
        $source = BranchWarehouseSource::where('branch_id', $locationId)
            ->where('item_id', $itemId)
            ->first();
        if ($source) return $source->warehouse_id;

        // ب) التحقق من المخزن الافتراضي للصنف نفسه (مثلاً "الجمبري" مخزنه الافتراضي هو "مخزن شريمب")
        $item = \App\Models\Item::find($itemId);
        if ($item && $item->default_warehouse_id) {
            return $item->default_warehouse_id;
        }

        // ج) التحقق من تطابق كلمات مفتاحية (صنف "جمبري" واسم مخزن "شريمب")
        if ($item) {
            $swh = Warehouse::where('client_id', $clientId)
                ->where('type', 'sub')
                ->where(function($q) use ($item) {
                    $q->where('name', 'like', '%' . $item->name . '%')
                      ->orWhereRaw('? like concat("%", name, "%")', [$item->name]);
                })
                ->first();
            if ($swh) return $swh->id;
        }

        // 4. مخزن افتراضي للموقع ده (كل الأصناف)
        $defaultSource = BranchWarehouseSource::where('branch_id', $locationId)
            ->whereNull('item_id')
            ->first();
        if ($defaultSource) return $defaultSource->warehouse_id;

        // 5. fallback للمخزن الرئيسي
        return Warehouse::where('client_id', $clientId)
            ->where('type', 'main')
            ->first()?->id;
    }

    // ── إدخال يدوي (Grid) ────────────────────────────────────

    /**
     * POST /api/vouchers/manual
     * حفظ إذن مدخول يدوياً من الـ Grid
     */
    public function manual(VoucherManualRequest $request): JsonResponse
    {
        $clientId = $request->user()->current_client_id;
        $userId   = $request->user()->id;

        // تأمين الـ IDs: تحويل الفاضي (empty string) إلى null عشان الـ foreign key ما ينكسرش
        $warehouseId = $request->warehouse_id ?: null;
        $branchId    = $request->branch_id ?: null;

        // لو إذن صرف من غير مخزن — نستنج المخزن المصدر من الصنف الأول
        if ($request->type === 'dispatch' && !$warehouseId) {
            $firstLine = $request->lines[0] ?? null;
            if ($firstLine && !empty($firstLine['item_id'])) {
                $item = Item::where('id', $firstLine['item_id'])->where('client_id', $clientId)->first();
                $warehouseId = $item?->default_warehouse_id;
            }
            if (!$warehouseId) {
                $warehouseId = Warehouse::where('client_id', $clientId)->where('type', 'main')->first()?->id;
            }
        }

        $order = DB::transaction(function () use ($request, $clientId, $userId, $warehouseId, $branchId) {
            $order = DispatchOrder::create([
                'client_id'    => $clientId,
                'type'         => $request->type,
                'date'         => $request->date,
                'warehouse_id' => $warehouseId,
                'branch_id'    => $branchId,
                'created_by'   => $userId,
                'status'       => 'confirmed',
                'source'       => 'manual',
            ]);

            foreach ($request->lines as $line) {
                $qty      = (float) $line['qty'];
                $cost     = (float) ($line['cost'] ?? 0);
                // للتوزيع (dispatch) بدون تكلفة — نحسب تلقائياً من default_cost
                if ($request->type === 'dispatch' && $cost <= 0) {
                    $item = Item::where('id', $line['item_id'])->where('client_id', $clientId)->first();
                    if ($item && $item->default_cost > 0) {
                        $cost = round($qty * $item->default_cost, 2);
                    }
                }
                $unitCost = $qty > 0 && $cost > 0 ? round($cost / $qty, 4) : 0;

                // لو "أول المدة" — بنمسح القديم لنفس الصنف والمخزن في نفس الشهر عشان ما يتراكمش (Replacement)
                if ($request->type === 'opening') {
                    $monthPrefix = substr($request->date, 0, 7); // 2024-05
                    
                    // 1. حذف من الـ Ledger
                    \App\Models\StockLedger::where('client_id', $clientId)
                        ->where('warehouse_id', $line['warehouse_id'])
                        ->where('item_id', $line['item_id'])
                        ->where('voucher_type', 'opening')
                        ->where('date', 'like', $monthPrefix . '%')
                        ->delete();
                    
                    // 2. حذف الـ Lines القديمة من الـ DispatchOrders اللي من نوع opening
                    // (ده تنظيف إضافي عشان التقارير تكون دقيقة)
                    $oldOrderIds = DispatchOrder::where('client_id', $clientId)
                        ->where('warehouse_id', $line['warehouse_id'])
                        ->where('type', 'opening')
                        ->where('date', 'like', $monthPrefix . '%')
                        ->pluck('id');
                    
                    DispatchLine::whereIn('order_id', $oldOrderIds)
                        ->where('item_id', $line['item_id'])
                        ->delete();
                }

                $lineWhId = $line['warehouse_id'] ?: $warehouseId;

                DispatchLine::create([
                    'order_id'     => $order->id,
                    'item_id'      => $line['item_id'],
                    'warehouse_id' => $lineWhId,
                    'qty'          => $qty,
                    'total_cost'   => $cost,
                    'unit_cost'    => $unitCost,
                    'date'         => $line['date'] ?? null,
                ]);

                $movementType = in_array($request->type, ['purchase', 'opening', 'adjustment', 'return']) ? 'in' : 'out';

                $this->ledger->post(
                    clientId:     $clientId,
                    whId:         $lineWhId,
                    itemId:       $line['item_id'],
                    date:         $request->date,
                    movementType: $movementType,
                    qty:          $qty,
                    totalCost:    $cost,
                    unitCost:     $unitCost,
                    refType:      'dispatch_order',
                    refId:        $order->id,
                    voucherType:  $request->type
                );

                if ($request->type === 'dispatch' && $branchId) {
                    $targetWhId = $this->resolveBranchTargetWhId($clientId, $branchId);
                    $this->ledger->post(
                        clientId:     $clientId,
                        whId:         $targetWhId,
                        itemId:       $line['item_id'],
                        date:         $request->date,
                        movementType: 'in',
                        qty:          $qty,
                        totalCost:    $cost,
                        unitCost:     $unitCost,
                        refType:      'dispatch_order',
                        refId:        $order->id,
                        voucherType:  $request->type
                    );
                }
            }

            return $order;
        });

        // auto-generate MonthlyClosing after opening balance save
        if ($request->type === 'opening' && $warehouseId && $request->date) {
            $month = substr($request->date, 0, 7);
            $this->calc->generateMonthlyClosing($clientId, $warehouseId, $month);
        }

        return response()->json(['message' => 'تم الحفظ', 'order_id' => $order->id], 201);
    }

    // ── تعديل إذن (Reverse + Re-apply) ─────────────────────────

    /**
     * PUT /api/vouchers/{order}
     * يعكس حركات المخزون القديمة، يحدث البيانات، ويعيد تطبيق الحركات الجديدة
     */
    public function update(VoucherManualRequest $request, DispatchOrder $order): JsonResponse
    {
        $clientId = $request->user()->current_client_id;
        $userId   = $request->user()->id;
        $month    = substr($request->date, 0, 7);

        // جمع الأصناف والمخازن المتأثرة قبل التعديل (القديمة والجديدة)
        $oldItems = $order->lines()->pluck('item_id')->unique()->toArray();
        $newItems = collect($request->lines)->pluck('item_id')->unique()->toArray();
        $allItemIds = array_unique(array_merge($oldItems, $newItems));

        $oldWarehouseIds = [$order->warehouse_id];
        if ($order->type === 'dispatch' && $order->branch_id) {
            $oldWarehouseIds[] = $this->resolveBranchTargetWhId($clientId, $order->branch_id);
        }
        $newWarehouseIds = [$request->warehouse_id];
        if ($request->type === 'dispatch' && !empty($request->branch_id)) {
            $newWarehouseIds[] = $this->resolveBranchTargetWhId($clientId, $request->branch_id);
        }
        $allWarehouseIds = array_unique(array_filter(array_merge($oldWarehouseIds, $newWarehouseIds)));

        DB::transaction(function () use ($request, $clientId, $userId, $order) {
            // 1. عكس الحركات القديمة وحذف السطور القديمة
            $this->ledger->reverseOrder($order->id);
            $order->lines()->delete();

            // 2. تحديث بيانات الإذن
            $order->update([
                'type'         => $request->type,
                'date'         => $request->date,
                'warehouse_id' => $request->warehouse_id ?: null,
                'branch_id'    => $request->branch_id ?: null,
            ]);

            // 3. إعادة إنشاء السطور والحركات (نفس منطق manual)
            foreach ($request->lines as $line) {
                $qty      = (float) $line['qty'];
                $cost     = (float) ($line['cost'] ?? 0);
                if ($request->type === 'dispatch' && $cost <= 0) {
                    $item = Item::where('id', $line['item_id'])->where('client_id', $clientId)->first();
                    if ($item && $item->default_cost > 0) {
                        $cost = round($qty * $item->default_cost, 2);
                    }
                }
                $unitCost = $qty > 0 && $cost > 0 ? round($cost / $qty, 4) : 0;

                DispatchLine::create([
                    'order_id'     => $order->id,
                    'item_id'      => $line['item_id'],
                    'warehouse_id' => $line['warehouse_id'],
                    'qty'          => $qty,
                    'total_cost'   => $cost,
                    'unit_cost'    => $unitCost,
                    'date'         => $line['date'] ?? null,
                ]);

                $movementType = in_array($request->type, ['purchase', 'opening', 'adjustment', 'return']) ? 'in' : 'out';

                $this->ledger->post(
                    clientId:     $clientId,
                    whId:         $line['warehouse_id'],
                    itemId:       $line['item_id'],
                    date:         $request->date,
                    movementType: $movementType,
                    qty:          $qty,
                    totalCost:    $cost,
                    unitCost:     $unitCost,
                    refType:      'dispatch_order',
                    refId:        $order->id,
                    voucherType:  $request->type
                );

                if ($request->type === 'dispatch' && !empty($request->branch_id)) {
                    $targetWhId = $this->resolveBranchTargetWhId($clientId, $request->branch_id);
                    $this->ledger->post(
                        clientId:     $clientId,
                        whId:         $targetWhId,
                        itemId:       $line['item_id'],
                        date:         $request->date,
                        movementType: 'in',
                        qty:          $qty,
                        totalCost:    $cost,
                        unitCost:     $unitCost,
                        refType:      'dispatch_order',
                        refId:        $order->id,
                        voucherType:  $request->type
                    );
                }

                if ($request->type === 'purchase' && $unitCost > 0) {
                    $item = Item::where('id', $line['item_id'])->where('client_id', $clientId)->first();
                    if ($item) {
                        $oldCost = $item->default_cost;
                        $calc = app(\App\Services\CostCalculationService::class);
                        $whId = $line['warehouse_id'];
                        if (!$whId) {
                            $wh = Warehouse::where('client_id', $clientId)->where('type', 'main')->first();
                            $whId = $wh ? $wh->id : null;
                        }
                        $avgCost = $whId ? $calc->weightedAverageCost($clientId, $whId, $item->id) : 0;
                        $newCost = $avgCost > 0 ? $avgCost : $unitCost;
                        $item->default_cost = $newCost;
                        $item->save();

                        // البحث عن لوج سابق لنفس الصنف + الفاتورة وتحديثه
                        $existingLog = ActivityLog::where('entity_type', 'Item')
                            ->where('entity_id', $item->id)
                            ->where('action', 'price_updated')
                            ->where('new_values->voucher_id', $order->id)
                            ->latest()
                            ->first();

                        if ($existingLog) {
                            $existingLog->update([
                                'new_values' => [
                                    'default_cost' => $newCost,
                                    'avg_cost' => $avgCost,
                                    'unit_cost' => $unitCost,
                                    'source' => 'voucher_confirm',
                                    'voucher_id' => $order->id,
                                    'corrected' => true,
                                ],
                            ]);
                        } elseif ((float) $oldCost !== $newCost) {
                            ActivityLogger::log(
                                action:     'price_updated',
                                entityType: 'Item',
                                entityId:   $item->id,
                                oldValues:  ['default_cost' => $oldCost],
                                newValues:  ['default_cost' => $newCost, 'avg_cost' => $avgCost, 'unit_cost' => $unitCost, 'source' => 'voucher_confirm', 'voucher_id' => $order->id],
                            );
                        }
                    }
                }
            }
        });

        // 4. تحديث التقفيل للأصناف والمخازن المتأثرة (قديمة وجديدة)
        $calc = app(\App\Services\CostCalculationService::class);
        foreach ($allWarehouseIds as $whId) {
            foreach ($allItemIds as $itemId) {
                $summary = $calc->itemMonthSummary($clientId, $whId, $itemId, $month);
                if ($summary['opening_qty'] == 0 && $summary['in_qty'] == 0 && $summary['out_qty'] == 0) {
                    MonthlyClosing::where('client_id', $clientId)
                        ->where('warehouse_id', $whId)
                        ->where('item_id', $itemId)
                        ->where('month', $month)
                        ->delete();
                } else {
                    MonthlyClosing::updateOrCreate(
                        [
                            'client_id'    => $clientId,
                            'warehouse_id' => $whId,
                            'item_id'      => $itemId,
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
                }
            }
        }

        return response()->json(['message' => 'تم تعديل الإذن وتحديث النظام بالكامل']);
    }

    // ── قائمة الأذون ─────────────────────────────────────────

    public function index(Request $request): JsonResponse
    {
        $clientId = $request->user()->current_client_id;

        $orders = DispatchOrder::where('client_id', $clientId)
            ->withCount('lines')
            ->with(['branch:id,name', 'warehouse:id,name', 'creator:id,name'])
            ->when($request->include_lines, fn($q) => $q->with(['lines.item:id,name,unit', 'lines.warehouse:id,name']))
            ->when($request->date_from, fn($q) => $q->where('date', '>=', $request->date_from))
            ->when($request->date_to,   fn($q) => $q->where('date', '<=', $request->date_to))
            ->when($request->type,         fn($q) => $q->where('type', $request->type))
            ->when($request->branch_id,    fn($q) => $q->where('branch_id', $request->branch_id))
            ->when($request->warehouse_id, fn($q) => $q->where('warehouse_id', $request->warehouse_id))
            ->orderByDesc('date')
            ->paginate((int) ($request->per_page ?? 100));

        // branch_id بيخزن Warehouse UUIDs (جدول branches فاضي)
        // نضيف warehouse محمل بالـ branch_id ك fallback
        $orders->getCollection()->transform(function ($order) {
            if (!$order->relationLoaded('warehouse') || !$order->warehouse) {
                if ($order->branch_id) {
                    $wh = \App\Models\Warehouse::find($order->branch_id);
                    if ($wh) $order->setRelation('warehouse', $wh);
                }
            }
            return $order;
        });

        return response()->json($orders);
    }

    public function show(DispatchOrder $order): JsonResponse
    {
        $order->load(['lines.item', 'lines.warehouse', 'branch', 'warehouse']);
        return response()->json($order);
    }

    public function destroy(DispatchOrder $order): JsonResponse
    {
        $clientId = $order->client_id;
        $month    = substr($order->date, 0, 7);

        // جمع الأصناف والمخازن المتأثرة قبل الحذف
        $order->load('lines');
        $itemIds      = $order->lines->pluck('item_id')->unique()->toArray();
        $warehouseIds = $order->lines->pluck('warehouse_id')->unique()->toArray();
        if ($order->warehouse_id) $warehouseIds[] = $order->warehouse_id;
        if ($order->type === 'dispatch' && $order->branch_id) {
            $warehouseIds[] = $this->resolveBranchTargetWhId($clientId, $order->branch_id);
        }
        $warehouseIds = array_unique(array_filter($warehouseIds));

        DB::transaction(function () use ($order) {
            $this->ledger->reverseOrder($order->id);
            $order->lines()->delete();
            $order->delete();
        });

        // تحديث التقفيل فقط للأصناف والمخازن المتأثرة (بدون مسح باقي الأصناف)
        $calc = app(\App\Services\CostCalculationService::class);
        foreach ($warehouseIds as $whId) {
            foreach ($itemIds as $itemId) {
                $summary = $calc->itemMonthSummary($clientId, $whId, $itemId, $month);
                if ($summary['opening_qty'] == 0 && $summary['in_qty'] == 0 && $summary['out_qty'] == 0) {
                    MonthlyClosing::where('client_id', $clientId)
                        ->where('warehouse_id', $whId)
                        ->where('item_id', $itemId)
                        ->where('month', $month)
                        ->delete();
                } else {
                    MonthlyClosing::updateOrCreate(
                        [
                            'client_id'    => $clientId,
                            'warehouse_id' => $whId,
                            'item_id'      => $itemId,
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
                }
            }
        }

        return response()->json(['message' => 'تم حذف الإذن وعكس حركات المخزون وتحديث التقفيل بنجاح']);
    }
}
