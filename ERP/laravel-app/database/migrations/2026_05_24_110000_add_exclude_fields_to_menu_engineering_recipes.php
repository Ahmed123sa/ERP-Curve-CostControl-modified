<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('menu_engineering_recipes', 'exclude_from_reconciliation')) {
            Schema::table('menu_engineering_recipes', function (Blueprint $table) {
                $table->boolean('exclude_from_reconciliation')->default(false)->after('status');
            });
        }
        if (!Schema::hasColumn('menu_engineering_recipes', 'exclude_from_menu')) {
            Schema::table('menu_engineering_recipes', function (Blueprint $table) {
                $table->boolean('exclude_from_menu')->default(false)->after('exclude_from_reconciliation');
            });
        }
    }

    public function down(): void
    {
        Schema::table('menu_engineering_recipes', function (Blueprint $table) {
            $table->dropColumn(['exclude_from_reconciliation', 'exclude_from_menu']);
        });
    }
};
