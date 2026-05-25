<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('production_market_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('client_id');
            $table->uuid('item_id');
            $table->integer('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['client_id', 'item_id']);
        });

        Schema::create('production_market_prices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('client_id');
            $table->uuid('item_id');
            $table->date('date');
            $table->decimal('price', 15, 4)->default(0);
            $table->timestamps();
            $table->unique(['client_id', 'item_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_market_prices');
        Schema::dropIfExists('production_market_items');
    }
};
