<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Item;
use App\Models\ItemMapping;
use App\Models\LocationMapping;
use App\Models\Client;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class ImportLegacyMappings extends Command
{
    protected $signature = 'erp:import-legacy {client_id}';
    protected $description = 'Import mappings from legacy mapping_memory.json for a specific client';

    public function handle()
    {
        $clientId = $this->argument('client_id');
        $client = Client::find($clientId);

        if (!$client) {
            $this->error("Client not found: {$clientId}");
            return;
        }

        $path = 'C:\\Users\\DELL\\Desktop\\app-Mr-mix-v2\\mapping_memory.json';
        if (!File::exists($path)) {
            $this->error("File not found at: {$path}");
            return;
        }

        $data = json_decode(File::get($path), true);
        
        $this->info("Importing mappings for client: {$client->name}");

        // 1. Import Department/Location Mappings
        foreach ($data['departments'] ?? [] as $source => $target) {
            // In the new system, we map to warehouse IDs. We'll try to find a warehouse by name.
            // But for now, we'll just store the string if we can't find a match.
            LocationMapping::updateOrCreate(
                ['client_id' => $clientId, 'source_name' => $source],
                ['target_type' => 'warehouse', 'confidence' => 100]
            );
        }

        // 2. Import Item Mappings
        // The JSON has "items": { "dept_name": { "source": "target" } }
        $itemsImported = 0;
        foreach ($data['items'] ?? [] as $dept => $mappings) {
            foreach ($mappings as $source => $targetName) {
                // Find item by name in this client
                $item = Item::where('client_id', $clientId)->where('name', $targetName)->first();
                
                if ($item) {
                    ItemMapping::updateOrCreate(
                        ['client_id' => $clientId, 'source_name' => $source],
                        ['item_id' => $item->id, 'context' => $dept, 'confidence' => 100]
                    );
                    $itemsImported++;
                }
            }
        }

        $this->info("Imported {$itemsImported} item mappings successfully!");
    }
}
