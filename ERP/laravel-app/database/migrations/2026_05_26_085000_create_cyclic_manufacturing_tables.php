<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cyclic_manufacturing', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('client_id');
            $table->uuid('item_id');
            $table->string('month', 7);
            $table->decimal('total_output_qty', 12, 4)->default(0);
            $table->decimal('total_input_cost', 15, 4)->default(0);
            $table->decimal('avg_unit_cost', 12, 4)->default(0);
            $table->decimal('output_ratio', 8, 4)->default(1);
            $table->json('output_qty_json')->nullable();
            $table->boolean('posted_to_production')->default(false);
            $table->timestamps();
        });

        Schema::create('cyclic_manufacturing_inputs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('cyclic_id');
            $table->uuid('item_id');
            $table->string('unit', 50)->nullable();
            $table->decimal('cost_per_unit', 12, 4)->default(0);
            $table->json('qty_json');
            $table->decimal('total_qty', 12, 4)->default(0);
            $table->decimal('line_total', 15, 4)->default(0);
            $table->timestamps();

            $table->foreign('cyclic_id')->references('id')->on('cyclic_manufacturing')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cyclic_manufacturing_inputs');
        Schema::dropIfExists('cyclic_manufacturing');
    }
};
