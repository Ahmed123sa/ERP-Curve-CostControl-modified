<?php
namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ClientModuleSeeder extends Seeder
{
    public function run(): void
    {
        $modules = [
            'dashboard', 'vouchers.purchase', 'vouchers.dispatch',
            'vouchers.upload', 'vouchers.history', 'closing',
            'inventory', 'items', 'mappings', 'menu-engineering',
            'reports.financial', 'reports.diffs', 'reports.food-cost',
            'analytics', 'expenses',
        ];

        $clients = DB::table('clients')->get();

        foreach ($clients as $client) {
            foreach ($modules as $key) {
                DB::table('client_modules')->insert([
                    'id' => (string) Str::uuid(),
                    'client_id' => $client->id,
                    'module_key' => $key,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            $this->command->info("Seeded {$client->name} with " . count($modules) . ' modules');
        }
    }
}
