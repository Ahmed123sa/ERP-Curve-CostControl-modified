<?php
require_once __DIR__ . '/ERP/laravel-app/vendor/autoload.php';
$app = require_once __DIR__ . '/ERP/laravel-app/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\DispatchOrder;
use App\Models\StockLedger;

$count = DispatchOrder::where('type', 'dispatch')->whereNull('branch_id')->count();
echo "Dispatch orders with null branch_id: $count\n";

$sample = DispatchOrder::where('type', 'dispatch')->whereNull('branch_id')->first();
if ($sample) {
    echo "Sample order: id={$sample->id}, warehouse_id={$sample->warehouse_id}, branch_id={$sample->branch_id}, date={$sample->date}\n";
    $ledgerCount = StockLedger::where('ref_type', 'dispatch_order')
        ->where('ref_id', $sample->id)
        ->count();
    echo "Stock ledger entries for this order: $ledgerCount\n";
    $ledgerIn = StockLedger::where('ref_type', 'dispatch_order')
        ->where('ref_id', $sample->id)
        ->where('movement_type', 'in')
        ->where('voucher_type', 'dispatch')
        ->first();
    if ($ledgerIn) {
        echo "Found 'in' movement: warehouse_id={$ledgerIn->warehouse_id}\n";
    }
}
