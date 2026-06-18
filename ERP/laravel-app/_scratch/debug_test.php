<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\Auth;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\Branch;
use App\Models\BranchWarehouseSource;

$user = User::where('email', 'admin@erp.local')->first();
Auth::login($user);
$clientId = $user->current_client_id;
echo "Logged in as: {$user->email}\n\n";

// TEST 1: Create sub warehouse
echo "=== TEST 1: Create sub warehouse ===\n";
try {
    $wh = Warehouse::create([
        'id' => (string) \Illuminate\Support\Str::uuid(),
        'client_id' => $clientId,
        'name' => 'مخزن اختبار ' . time(),
        'type' => 'sub',
        'is_active' => true,
    ]);
    echo "  ✅ Warehouse created: {$wh->name} ({$wh->type})\n";

    $branch = Branch::where('client_id', $clientId)->where('name', $wh->name)->first();
    echo $branch ? "  ✅ Branch auto-created: {$branch->name}\n" : "  ❌ Branch NOT created!\n";

    $source = BranchWarehouseSource::where('branch_id', $branch->id ?? '')->where('warehouse_id', $wh->id)->first();
    echo $source ? "  ✅ Branch-Warehouse source linked\n" : "  ❌ Branch-Warehouse source NOT linked!\n";
} catch (\Exception $e) {
    echo "  ❌ Error: " . $e->getMessage() . "\n";
    echo "  " . $e->getFile() . ":" . $e->getLine() . "\n";
}

// TEST 2: Create main warehouse
echo "\n=== TEST 2: Create main warehouse ===\n";
try {
    $wh2 = Warehouse::create([
        'id' => (string) \Illuminate\Support\Str::uuid(),
        'client_id' => $clientId,
        'name' => 'مخزن رئيسي اختبار ' . time(),
        'type' => 'main',
        'is_active' => true,
    ]);
    echo "  ✅ Warehouse created: {$wh2->name}\n";

    $links = BranchWarehouseSource::where('warehouse_id', $wh2->id)->count();
    echo "  ✅ Linked to {$links} branch(es)\n";
} catch (\Exception $e) {
    echo "  ❌ Error: " . $e->getMessage() . "\n";
}

// TEST 3: Simulate closing generate
echo "\n=== TEST 3: Closing generate ===\n";
try {
    $mainWh = Warehouse::where('client_id', $clientId)->where('type', 'main')->first();
    $calc = app(\App\Services\CostCalculationService::class);
    $results = $calc->generateMonthlyClosing($clientId, $mainWh->id, '2026-05');
    echo "  ✅ Closing generated: {$mainWh->name} — " . count($results) . " items\n";
} catch (\Exception $e) {
    echo "  ❌ Error: " . $e->getMessage() . "\n";
}

// TEST 4: Test allWarehouses endpoint response
echo "\n=== TEST 4: Test allWarehouses ===\n";
try {
    $closings = \App\Models\MonthlyClosing::where('client_id', $clientId)->where('month', '2026-05')->with('item', 'warehouse')->get();
    echo "  ✅ Closings found: {$closings->count()}\n";
} catch (\Exception $e) {
    echo "  ❌ Error: " . $e->getMessage() . "\n";
}

echo "\n=== All tests done! ===\n";