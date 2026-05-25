<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('slaughters', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('client_id');
            $table->date('date');
            $table->uuid('animal_item_id');
            $table->decimal('live_weight', 12, 4)->default(0);
            $table->decimal('price_per_kg', 12, 4)->default(0);
            $table->decimal('transport_slaughter_cost', 12, 4)->default(0);
            $table->decimal('total_cost', 15, 4)->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('slaughter_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('slaughter_id');
            $table->uuid('item_id');
            $table->string('unit', 50)->nullable();
            $table->decimal('weight', 12, 4)->default(0);
            $table->decimal('selling_price', 12, 4)->default(0);
            $table->decimal('total', 15, 4)->default(0);
            $table->decimal('allocation_pct', 8, 4)->default(0);
            $table->decimal('actual_cost_per_kg', 12, 4)->default(0);
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->foreign('slaughter_id')->references('id')->on('slaughters')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('slaughter_items');
        Schema::dropIfExists('slaughters');
    }
};
