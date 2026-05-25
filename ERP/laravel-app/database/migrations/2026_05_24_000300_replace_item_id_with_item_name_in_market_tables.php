<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_market_items', function (Blueprint $table) {
            $table->dropUnique(['client_id', 'item_id']);
            $table->dropColumn('item_id');
        });
        Schema::table('production_market_items', function (Blueprint $table) {
            $table->string('item_name')->after('client_id');
            $table->string('unit')->nullable()->after('item_name');
            $table->unique(['client_id', 'item_name']);
        });

        Schema::table('production_market_prices', function (Blueprint $table) {
            $table->dropUnique(['client_id', 'item_id', 'date']);
            $table->dropColumn('item_id');
        });
        Schema::table('production_market_prices', function (Blueprint $table) {
            $table->string('item_name')->after('client_id');
            $table->unique(['client_id', 'item_name', 'date']);
        });
    }

    public function down(): void
    {
        Schema::table('production_market_items', function (Blueprint $table) {
            $table->dropUnique(['client_id', 'item_name']);
            $table->dropColumn(['item_name', 'unit']);
        });
        Schema::table('production_market_items', function (Blueprint $table) {
            $table->uuid('item_id')->after('client_id');
            $table->unique(['client_id', 'item_id']);
        });

        Schema::table('production_market_prices', function (Blueprint $table) {
            $table->dropUnique(['client_id', 'item_name', 'date']);
            $table->dropColumn('item_name');
        });
        Schema::table('production_market_prices', function (Blueprint $table) {
            $table->uuid('item_id')->after('client_id');
            $table->unique(['client_id', 'item_id', 'date']);
        });
    }
};
