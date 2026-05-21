<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('menu_engineering_categories', function (Blueprint $table) {
            $table->uuid('menu_id')->nullable()->after('client_id');
            $table->foreign('menu_id')->references('id')->on('menu_engineering_menus')->nullOnDelete();
            $table->index('menu_id');
            $table->dropUnique('menu_engineering_categories_client_id_name_unique');
            $table->unique(['client_id', 'menu_id', 'name'], 'menu_cat_per_menu_unique');
        });
    }

    public function down(): void
    {
        Schema::table('menu_engineering_categories', function (Blueprint $table) {
            $table->dropForeign(['menu_id']);
            $table->dropIndex(['menu_id']);
            $table->dropUnique('menu_cat_per_menu_unique');
            $table->unique(['client_id', 'name'], 'menu_engineering_categories_client_id_name_unique');
            $table->dropColumn('menu_id');
        });
    }
};
