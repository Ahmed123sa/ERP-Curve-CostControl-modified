<?php
require __DIR__ . "/../vendor/autoload.php";
$app = require_once __DIR__ . "/../bootstrap/app.php";
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

// Authenticate as admin user with ZAHRA client
$user = App\Models\User::find("2d0f781f-0194-4ffb-b504-d6f7635ad0a7");
$user->current_client_id = "9a4eec9b-5b4d-4dbd-8ca1-0c079abc5ea5";
Auth::login($user);

echo "Authenticated as: " . Auth::user()->name . "\n";
echo "Client ID: " . Auth::user()->current_client_id . "\n";

$service = app(App\Services\CostCalculationService::class);
$warehouseId = "fc133ba7-7a70-48e4-ac77-e7c12b3c08f7";
$month = "2026-06";
$clientId = Auth::user()->current_client_id;

echo "Calling generateMonthlyClosing for warehouse $warehouseId, month $month...\n";
echo "This will process " . App\Models\Item::where("client_id", $clientId)->where("is_active", true)->count() . " active items.\n";

$start = microtime(true);
$results = $service->generateMonthlyClosing($clientId, $warehouseId, $month);
$elapsed = microtime(true) - $start;

echo "Execution time: " . round($elapsed, 4) . " seconds\n";
echo "Results count: " . count($results) . "\n";
