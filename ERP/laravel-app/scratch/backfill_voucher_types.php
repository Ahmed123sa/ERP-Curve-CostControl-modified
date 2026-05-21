<?php
// erp/laravel-app/scratch/backfill_voucher_types.php
use App\Models\StockLedger;
use App\Models\DispatchOrder;

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$ledgers = StockLedger::where('ref_type', 'dispatch_order')
    ->where(function($q) {
        $q->whereNull('voucher_type')->orWhere('voucher_type', '');
    })->get();

$count = 0;
foreach ($ledgers as $l) {
    $o = DispatchOrder::find($l->ref_id);
    if ($o) {
        $l->update(['voucher_type' => $o->type]);
        $count++;
    }
}

echo "Successfully backfilled $count ledger entries.\n";
