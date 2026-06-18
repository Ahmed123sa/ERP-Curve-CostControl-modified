<?php

use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $existingLogo = Setting::where('key', 'system_logo')->first();
        if ($existingLogo && $existingLogo->value) {
            return;
        }

        $clientLogo = DB::table('clients')->whereNotNull('logo')->value('logo');
        if ($clientLogo) {
            Setting::firstOrCreate(
                ['key' => 'system_logo'],
                ['value' => $clientLogo]
            );
        }
    }

    public function down(): void
    {
        Setting::where('key', 'system_logo')->delete();
    }
};
