<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS menu_eng_menu_unique ON menu_engineering_menus (client_id, branch_id, name)');
            DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS menu_eng_cat_unique ON menu_engineering_categories (client_id, menu_id, name)');
        } else {
            DB::statement('ALTER TABLE menu_engineering_menus ADD UNIQUE INDEX menu_eng_menu_unique (client_id, branch_id, name)');
            DB::statement('ALTER TABLE menu_engineering_categories ADD UNIQUE INDEX menu_eng_cat_unique (client_id, menu_id, name)');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('DROP INDEX IF EXISTS menu_eng_cat_unique');
            DB::statement('DROP INDEX IF EXISTS menu_eng_menu_unique');
        } else {
            DB::statement('ALTER TABLE menu_engineering_menus DROP INDEX IF EXISTS menu_eng_menu_unique');
            DB::statement('ALTER TABLE menu_engineering_categories DROP INDEX IF EXISTS menu_eng_cat_unique');
        }
    }
};
