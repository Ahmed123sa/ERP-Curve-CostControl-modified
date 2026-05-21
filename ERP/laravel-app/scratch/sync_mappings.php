<?php
// erp/laravel-app/scratch/sync_mappings.php
use App\Models\LocationMapping;
use App\Models\Warehouse;

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$mappings = LocationMapping::all();
$count = 0;

foreach ($mappings as $m) {
    $w = Warehouse::find($m->target_id);
    if ($w) {
        $m->update(['target_type' => $w->type]);
        $count++;
    }
}

echo "Successfully synced $count location mapping types.\n";
