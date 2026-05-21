<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Item;
use App\Models\Client;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class ImportLegacyItems extends Command
{
    protected $signature = 'erp:import-items {client_id}';
    protected $description = 'Import unique items from legacy mapping_memory.json';

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
        $uniqueItemNames = [];

        foreach ($data['items'] ?? [] as $dept => $mappings) {
            foreach ($mappings as $source => $targetName) {
                $uniqueItemNames[$targetName] = true;
            }
        }

        $this->info("Found " . count($uniqueItemNames) . " unique items. Importing...");

        $count = 0;
        foreach (array_keys($uniqueItemNames) as $name) {
            Item::updateOrCreate(
                ['client_id' => $clientId, 'name' => $name],
                ['unit' => 'قطعة', 'category' => 'خامات', 'is_active' => true]
            );
            $count++;
        }

        $this->info("Imported {$count} items successfully!");
    }
}
