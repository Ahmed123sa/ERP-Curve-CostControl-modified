<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('processing_batch_days', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('client_id');
            $table->uuid('batch_id');
            $table->date('date');
            $table->json('processes')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->foreign('batch_id')->references('id')->on('processing_batches')->cascadeOnDelete();
        });

        Schema::table('processing_batch_inputs', function (Blueprint $table) {
            $table->uuid('batch_day_id')->nullable()->after('batch_id');
            $table->foreign('batch_day_id')->references('id')->on('processing_batch_days')->cascadeOnDelete();
            $table->uuid('batch_id')->nullable()->change();
        });

        Schema::table('processing_batch_outputs', function (Blueprint $table) {
            $table->uuid('batch_day_id')->nullable()->after('batch_id');
            $table->foreign('batch_day_id')->references('id')->on('processing_batch_days')->cascadeOnDelete();
            $table->uuid('batch_id')->nullable()->change();
        });

        Schema::table('processing_batches', function (Blueprint $table) {
            $table->date('date')->nullable()->change();
            $table->json('processes')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('processing_batch_inputs', function (Blueprint $table) {
            $table->dropForeign(['batch_day_id']);
            $table->dropColumn('batch_day_id');
        });

        Schema::table('processing_batch_outputs', function (Blueprint $table) {
            $table->dropForeign(['batch_day_id']);
            $table->dropColumn('batch_day_id');
        });

        Schema::dropIfExists('processing_batch_days');
    }
};
