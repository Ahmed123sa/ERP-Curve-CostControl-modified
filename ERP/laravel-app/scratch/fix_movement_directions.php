<?php
// erp/laravel-app/scratch/fix_movement_directions.php
use App\Models\StockLedger;
use App\Models\DispatchOrder;

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

// حركات الـ Dispatch التي سجلت بالخطأ كـ In وهي Out
$ledgers = StockLedger::where('ref_type', 'dispatch_order')->get();

$count = 0;
foreach ($ledgers as $l) {
    // لو الحركة هي من مخزن (رئيسي أو فرعي) ورايحه لفرع، يبقى لازم تكون Out
    $o = DispatchOrder::find($l->ref_id);
    if ($o) {
        $l->update(['voucher_type' => $o->type]);
        
        // تصحيح اتجاه الحركة
        if ($o->type === 'dispatch') {
             // لو المخزن في السطر هو نفس مخزن الطلب (المصدر) -> Out
             if ($l->warehouse_id === $o->warehouse_id) {
                 $l->update(['movement_type' => 'out']);
             } else {
                 $l->update(['movement_type' => 'in']);
             }
        } elseif ($o->type === 'purchase') {
             $l->update(['movement_type' => 'in']);
        }
        $count++;
    }
}

echo "Successfully fixed directions and types for $count ledger entries.\n";
