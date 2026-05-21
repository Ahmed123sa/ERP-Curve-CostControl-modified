<?php
// erp/laravel-app/scratch/cleanup_directions.php
use App\Models\DispatchOrder;
use App\Models\StockLedger;
use App\Models\Warehouse;

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

// 1. مسح كل حركات الـ Ledger القديمة المتعلقة بالأذون للبدء من جديد بنظام نظيف
echo "Cleaning up ledger...\n";
StockLedger::where('ref_type', 'dispatch_order')->delete();

// 2. إعادة توليد كل الحركات بناءً على المنطق الجديد
$orders = DispatchOrder::with('lines')->get();
$count = 0;

foreach ($orders as $order) {
    foreach ($order->lines as $line) {
        $sourceWhId = null;
        $destWhId   = null;

        if ($order->type === 'dispatch') {
            // حل المصدر
            $sourceWhId = $line->warehouse_id; // عادة متخزن في السطر
            $destWhId   = $order->branch_id ?? $order->warehouse_id;

            if ($sourceWhId) {
                StockLedger::create([
                    'client_id'     => $order->client_id,
                    'warehouse_id'  => $sourceWhId,
                    'item_id'       => $line->item_id,
                    'date'          => $order->date,
                    'movement_type' => 'out',
                    'qty'           => $line->qty,
                    'unit_cost'     => $line->unit_cost,
                    'total_cost'    => $line->total_cost,
                    'ref_type'      => 'dispatch_order',
                    'ref_id'        => $order->id,
                ]);
            }

            if ($destWhId && $destWhId !== $sourceWhId) {
                StockLedger::create([
                    'client_id'     => $order->client_id,
                    'warehouse_id'  => $destWhId,
                    'item_id'       => $line->item_id,
                    'date'          => $order->date,
                    'movement_type' => 'in',
                    'qty'           => $line->qty,
                    'unit_cost'     => $line->unit_cost,
                    'total_cost'    => $line->total_cost,
                    'ref_type'      => 'dispatch_order',
                    'ref_id'        => $order->id,
                ]);
            }
        } else {
            // مشتريات
            $destWhId = $order->warehouse_id ?? $order->branch_id;
            if ($destWhId) {
                StockLedger::create([
                    'client_id'     => $order->client_id,
                    'warehouse_id'  => $destWhId,
                    'item_id'       => $line->item_id,
                    'date'          => $order->date,
                    'movement_type' => 'in',
                    'qty'           => $line->qty,
                    'unit_cost'     => $line->unit_cost,
                    'total_cost'    => $line->total_cost,
                    'ref_type'      => 'dispatch_order',
                    'ref_id'        => $order->id,
                ]);
            }
        }
        $count++;
    }
}

echo "Successfully re-generated $count movements with correct directions.\n";
