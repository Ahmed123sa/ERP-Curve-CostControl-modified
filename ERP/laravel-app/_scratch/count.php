<?php
require __DIR__ . "/../vendor/autoload.php";
$app = require_once __DIR__ . "/../bootstrap/app.php";
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$items = App\Models\Item::where("is_active", true)->count();
echo "Active items: $items\n";

$warehouses = App\Models\Warehouse::where("is_active", true)->count();
echo "Active warehouses: $warehouses\n";

$clients = App\Models\Client::where("name", "like", "%ZAHRA%")->get(["id", "name"]);
if ($clients->count() > 0) {
    foreach ($clients as $c) {
        echo "ZAHRA client: " . $c->id . " - " . $c->name . "\n";
    }
} else {
    echo "No ZAHRA client found, listing all clients:\n";
    foreach (App\Models\Client::all(["id", "name"]) as $c) {
        echo "  " . $c->id . " - " . $c->name . "\n";
    }
}
