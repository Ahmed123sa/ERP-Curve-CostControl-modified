<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $clients = DB::table('clients')->get();
        foreach ($clients as $client) {
            $exists = DB::table('client_modules')
                ->where('client_id', $client->id)
                ->where('module_key', 'expenses')
                ->exists();
            if (! $exists) {
                DB::table('client_modules')->insert([
                    'id' => (string) Str::uuid(),
                    'client_id' => $client->id,
                    'module_key' => 'expenses',
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        DB::table('client_modules')
            ->where('module_key', 'expenses')
            ->delete();
    }
};
