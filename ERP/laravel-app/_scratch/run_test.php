<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Client;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\Branch;
use App\Models\StockLedger;
use App\Models\DispatchOrder;
use App\Models\MonthlyClosing;
use App\Services\VoucherParserService;
use App\Services\MappingService;
use App\Services\StockLedgerService;
use App\Services\CostCalculationService;
use Illuminate\Support\Facades\DB;

$client = Client::first();
$user = User::first();
$mainWh = Warehouse::where('type', 'main')->first();

if (!$mainWh) die("No main warehouse found!\n");

echo "1. Wiping data...\n";
StockLedger::truncate();
DB::table('dispatch_lines')->truncate();
DB::statement('SET FOREIGN_KEY_CHECKS=0;');
DispatchOrder::truncate();
DB::statement('SET FOREIGN_KEY_CHECKS=1;');
MonthlyClosing::truncate();

$parser = app(VoucherParserService::class);
$mapper = app(MappingService::class);
$ledger = app(StockLedgerService::class);
$calc = app(CostCalculationService::class);

$filesToTest = [
    'G:/CostControl/MrMix/2026/5/12-5/وارد مخزن_12_5.xlsx',
    'G:/CostControl/MrMix/2026/5/12-5/معمل_12_5.xlsx',
    'G:/CostControl/MrMix/2026/5/12-5/مستر مكس بلقاس_12_5.xlsx',
    'G:/CostControl/MrMix/2026/5/12-5/ماي بروست_12_5.xlsx',
];

echo "2. Processing files...\n";
foreach($filesToTest as $file) {
    if (!file_exists($file)) continue;
    
    $parsed = $parser->parse($file);
    foreach ($parsed['vouchers'] as $voucher) {
        $location = $mapper->findLocation($client->id, $voucher['location']);
        $type = $parser->detectVoucherType($voucher['location']);
        
        $warehouseId = $location['type'] === 'warehouse' ? $location['id'] : null;
        $branchId = $location['type'] === 'branch' ? $location['id'] : null;
        
        // If it's a branch dispatch, the source warehouse is mainWh
        if ($location['type'] === 'branch') {
            $warehouseId = $mainWh->id;
        } elseif ($location['type'] === 'warehouse' && empty($warehouseId)) {
            $warehouseId = $mainWh->id; // fallback to main
        }
        
        $order = DispatchOrder::create([
            'client_id' => $client->id,
            'type' => $type,
            'date' => $voucher['date'],
            'warehouse_id' => $warehouseId,
            'branch_id' => $branchId,
            'created_by' => $user->id,
            'status' => 'confirmed',
        ]);
        
        foreach ($voucher['items'] as $item) {
            $match = $mapper->findItem($client->id, $item['name'], $voucher['location']);
            if (!$match['item_id']) continue; // skip unknown items
            
            $qty = (float)$item['qty'];
            $cost = (float)$item['cost'];
            $unitCost = $qty > 0 && $cost > 0 ? round($cost / $qty, 4) : 0;
            
            DB::table('dispatch_lines')->insert([
                'id' => Str::uuid(),
                'order_id' => $order->id,
                'item_id' => $match['item_id'],
                'warehouse_id' => $warehouseId,
                'qty' => $qty,
                'total_cost' => $cost,
                'unit_cost' => $unitCost,
                'created_at' => now(),
                'updated_at' => now()
            ]);
            
            $ledger->post(
                clientId: $client->id,
                whId: $warehouseId,
                itemId: $match['item_id'],
                date: $voucher['date'],
                type: $type,
                qty: $qty,
                totalCost: $cost,
                unitCost: $unitCost,
                refType: 'dispatch_order',
                refId: $order->id
            );
        }
        echo "   -> Saved $type for $voucher[location]\n";
    }
}

echo "3. Generating Monthly Closing...\n";
$warehouses = Warehouse::where('client_id', $client->id)->get();
foreach ($warehouses as $wh) {
    $calc->generateMonthlyClosing($client->id, $wh->id, '2026-05');
}

echo "4. Checking results for 'لبن'...\n";
$item = \App\Models\Item::where('name', 'لبن')->first();
if ($item) {
    $closing = MonthlyClosing::where('item_id', $item->id)->first();
    if ($closing) {
        echo json_encode($closing->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    } else {
        echo "No closing found for لبن.\n";
    }
}
