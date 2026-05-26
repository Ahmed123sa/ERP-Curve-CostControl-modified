<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('processing_batches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('client_id');
            $table->date('date');
            $table->string('name');
            $table->json('processes')->nullable();
            $table->decimal('total_input_cost', 15, 4)->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('processing_batch_inputs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('batch_id');
            $table->uuid('item_id');
            $table->decimal('qty', 12, 4)->default(0);
            $table->decimal('cost_per_kg', 12, 4)->default(0);
            $table->decimal('line_total', 15, 4)->default(0);
            $table->timestamps();

            $table->foreign('batch_id')->references('id')->on('processing_batches')->cascadeOnDelete();
        });

        Schema::create('processing_batch_outputs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('batch_id');
            $table->uuid('item_id');
            $table->decimal('qty', 12, 4)->default(0);
            $table->decimal('effective_cost_per_kg', 12, 4)->default(0);
            $table->decimal('total_cost', 15, 4)->default(0);
            $table->decimal('allocation_pct', 8, 4)->default(0);
            $table->timestamps();

            $table->foreign('batch_id')->references('id')->on('processing_batches')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('processing_batch_outputs');
        Schema::dropIfExists('processing_batch_inputs');
        Schema::dropIfExists('processing_batches');
    }
};
