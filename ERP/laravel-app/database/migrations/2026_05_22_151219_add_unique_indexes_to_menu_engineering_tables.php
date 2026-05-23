<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE menu_engineering_menus ADD UNIQUE INDEX menu_eng_menu_unique (client_id, branch_id, name)');
        DB::statement('ALTER TABLE menu_engineering_categories ADD UNIQUE INDEX menu_eng_cat_unique (client_id, menu_id, name)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE menu_engineering_menus DROP INDEX IF EXISTS menu_eng_menu_unique');
        DB::statement('ALTER TABLE menu_engineering_categories DROP INDEX IF EXISTS menu_eng_cat_unique');
    }
};
