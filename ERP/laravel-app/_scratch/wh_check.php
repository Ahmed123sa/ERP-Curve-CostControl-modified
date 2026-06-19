<?php
require __DIR__ . "/../vendor/autoload.php";
$app = require_once __DIR__ . "/../bootstrap/app.php";
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$wh = App\Models\Warehouse::find("fc133ba7-7a70-48e4-ac77-e7c12b3c08f7");
if ($wh) {
    echo "Warehouse: " . $wh->name . " (type: " . $wh->type . ", client_id: " . $wh->client_id . ")\n";
} else {
    echo "Warehouse not found\n";
}
