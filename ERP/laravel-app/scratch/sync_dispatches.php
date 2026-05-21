<?php
// erp/laravel-app/scratch/sync_dispatches.php
use App\Models\DispatchOrder;
use App\Models\StockLedger;
use App\Models\DispatchLine;

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$orders = DispatchOrder::where('type', 'dispatch')->whereNotNull('branch_id')->get();
$count = 0;

foreach ($orders as $order) {
    foreach ($order->lines as $line) {
        $exists = StockLedger::where('ref_type', 'dispatch_order')
            ->where('ref_id', $order->id)
            ->where('warehouse_id', $order->branch_id)
            ->where('item_id', $line->item_id)
            ->where('movement_type', 'in')
            ->exists();

        if (!$exists) {
            StockLedger::create([
                'client_id'     => $order->client_id,
                'warehouse_id'  => $order->branch_id,
                'item_id'       => $line->item_id,
                'date'          => $order->date,
                'movement_type' => 'in',
                'qty'           => $line->qty,
                'unit_cost'     => $line->unit_cost,
                'total_cost'    => $line->total_cost,
                'ref_type'      => 'dispatch_order',
                'ref_id'        => $order->id,
            ]);
            $count++;
        }
    }
}

echo "Successfully synced $count missing branch receipts.\n";
