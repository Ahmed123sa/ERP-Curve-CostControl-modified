<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('menu_engineering_menus', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('client_id');
            $table->uuid('branch_id');
            $table->string('name', 100);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
            $table->index('client_id');
            $table->index('branch_id');
            $table->unique(['client_id', 'branch_id', 'name']);
        });

        Schema::table('menu_engineering_recipes', function (Blueprint $table) {
            $table->uuid('menu_id')->nullable()->after('branch_id');
            $table->foreign('menu_id')->references('id')->on('menu_engineering_menus')->nullOnDelete();
            $table->index('menu_id');
        });
    }

    public function down(): void
    {
        Schema::table('menu_engineering_recipes', function (Blueprint $table) {
            $table->dropForeign(['menu_id']);
            $table->dropIndex(['menu_id']);
            $table->dropColumn('menu_id');
        });
        Schema::dropIfExists('menu_engineering_menus');
    }
};
