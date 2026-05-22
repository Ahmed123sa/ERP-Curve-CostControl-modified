<?php
require __DIR__ . '\ERP\laravel-app\vendor\autoload.php';
$app = require __DIR__ . '\ERP\laravel-app\bootstrap\app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$r = App\Models\Production\Recipe::first();
echo "Before: " . json_encode($r->sizes) . "\n";

$r->sizes = [['grams' => 100, 'selling_price' => null, 'item_id' => $r->item_id]];
$r->save();
echo "Saved\n";

$r2 = App\Models\Production\Recipe::find($r->id);
echo "After: " . json_encode($r2->sizes) . "\n";

// Reset
$r2->sizes = null;
$r2->save();
echo "Reset\n";
