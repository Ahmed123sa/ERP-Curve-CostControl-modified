<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Warehouse;
use App\Models\Branch;
use App\Models\LocationMapping;
use App\Models\Client;

$client = Client::first();

$branchNames = ['معمل', 'ماي بروست', 'مستر مكس بلقاس', 'مستر مكس دمياط', 'مستر شريمب', 'مستر ميكس الساحل'];

foreach($branchNames as $bName) {
    $wh = Warehouse::where('client_id', $client->id)->where('name', $bName)->first();
    if ($wh) {
        $branch = Branch::firstOrCreate([
            'client_id' => $client->id,
            'name'      => $bName,
        ], [
            'is_active' => true
        ]);
        
        LocationMapping::where('target_id', $wh->id)
            ->update([
                'target_type' => 'branch',
                'target_id'   => $branch->id
            ]);
            
        $wh->delete();
        echo "Migrated $bName to Branch.\n";
    }
}

$mainWh = Warehouse::where('client_id', $client->id)->where('type', 'main')->first();
if ($mainWh) {
    LocationMapping::updateOrCreate(
        ['client_id' => $client->id, 'source_name' => 'وارد مخزن'],
        ['target_type' => 'warehouse', 'target_id' => $mainWh->id, 'confidence' => 100]
    );
    echo "Mapped وارد مخزن to " . $mainWh->name . "\n";
}
