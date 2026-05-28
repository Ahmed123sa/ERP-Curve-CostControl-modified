<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Fill source_name for old dispatch_lines where source_name IS NULL
        // by matching item_id + client_id against ItemMapping.
        // If multiple source_names exist for one item_id, pick any (MIN).
        DB::statement("
            UPDATE dispatch_lines dl
            JOIN (
                SELECT client_id, item_id, MIN(source_name) AS source_name
                FROM item_mappings
                WHERE source_name IS NOT NULL
                GROUP BY client_id, item_id
            ) im ON dl.client_id = im.client_id AND dl.item_id = im.item_id
            SET dl.source_name = im.source_name
            WHERE dl.source_name IS NULL
        ");
    }

    public function down(): void
    {
        // cannot reverse — data is already set in confirm() for new records
    }
};
