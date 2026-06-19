<?php
require __DIR__ . "/../vendor/autoload.php";
$app = require_once __DIR__ . "/../bootstrap/app.php";
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$user = App\Models\User::find("2d0f781f-0194-4ffb-b504-d6f7635ad0a7");
$user->current_client_id = "9a4eec9b-5b4d-4dbd-8ca1-0c079abc5ea5";
Auth::login($user);

$warehouseId = "fc133ba7-7a70-48e4-ac77-e7c12b3c08f7";
$clientId = "9a4eec9b-5b4d-4dbd-8ca1-0c079abc5ea5";
$month = "2026-06";

// Items with ledger activity in June 2026 for this warehouse
$activeItemIds = App\Models\StockLedger::where("client_id", $clientId)
    ->where("warehouse_id", $warehouseId)
    ->whereBetween("date", ["2026-06-01", "2026-06-30"])
    ->distinct("item_id")
    ->pluck("item_id");

echo "Items with ledger activity in June 2026 at bar warehouse: " . $activeItemIds->count() . "\n";

// Items that already have monthly_closings records for June 2026
$existingClosings = App\Models\MonthlyClosing::where("client_id", $clientId)
    ->where("warehouse_id", $warehouseId)
    ->where("month", $month)
    ->count();
echo "Existing monthly closings for June 2026 at bar warehouse: $existingClosings\n";

// Total active items
$totalActive = App\Models\Item::where("client_id", $clientId)->where("is_active", true)->count();
echo "Total active items for ZAHRA: $totalActive\n";

// Count items per warehouse type
$whTypes = App\Models\Warehouse::where("client_id", $clientId)->where("is_active", true)
    ->selectRaw("type, count(*) as cnt")
    ->groupBy("type")
    ->pluck("cnt", "type");
echo "Warehouse types:\n";
foreach ($whTypes as $t => $c) {
    echo "  $t: $c\n";
}
