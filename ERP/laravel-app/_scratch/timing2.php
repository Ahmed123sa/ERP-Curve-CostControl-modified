<?php
require __DIR__ . "/../vendor/autoload.php";
$app = require_once __DIR__ . "/../bootstrap/app.php";
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$user = App\Models\User::find("2d0f781f-0194-4ffb-b504-d6f7635ad0a7");
$user->current_client_id = "9a4eec9b-5b4d-4dbd-8ca1-0c079abc5ea5";
Auth::login($user);

// Enable query log
DB::enableQueryLog();

$service = app(App\Services\CostCalculationService::class);
$warehouseId = "fc133ba7-7a70-48e4-ac77-e7c12b3c08f7";
$month = "2026-06";
$clientId = Auth::user()->current_client_id;

echo "Processing " . App\Models\Item::where("client_id", $clientId)->where("is_active", true)->count() . " items...\n";

$start = microtime(true);
$results = $service->generateMonthlyClosing($clientId, $warehouseId, $month);
$elapsed = microtime(true) - $start;

$queries = DB::getQueryLog();
$totalQueries = count($queries);
$totalTime = array_sum(array_column($queries, "time"));

echo "Execution time: " . round($elapsed, 4) . " seconds\n";
echo "Total SQL queries: $totalQueries\n";
echo "Total query time (ms): " . round($totalTime, 2) . " ms\n";
echo "Results count: " . count($results) . "\n";

// Show all SELECT queries vs write queries
$selects = 0; $writes = 0;
foreach ($queries as $q) {
    $sql = $q["query"];
    if (preg_match("/^(select|SELECT)/", $sql)) {
        $selects++;
    } else {
        $writes++;
    }
}
echo "SELECT queries: $selects\n";
echo "Write queries (INSERT/UPDATE/DELETE): $writes\n";

// Show first few queries to understand the pattern
echo "\nFirst 5 queries:\n";
for ($i = 0; $i < min(5, $totalQueries); $i++) {
    echo ($i+1) . ". " . $queries[$i]["query"] . " [" . round($queries[$i]["time"], 2) . "ms]\n";
}
echo "\nLast 5 queries:\n";
for ($i = max(0, $totalQueries-5); $i < $totalQueries; $i++) {
    echo ($i+1) . ". " . $queries[$i]["query"] . " [" . round($queries[$i]["time"], 2) . "ms]\n";
}
