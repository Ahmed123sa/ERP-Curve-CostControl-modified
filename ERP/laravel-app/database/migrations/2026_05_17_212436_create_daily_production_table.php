<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_production', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('client_id');
            $table->uuid('recipe_id');
            $table->date('date');
            $table->decimal('qty', 12, 4)->comment('عدد البورشنات/الكمية المنتجة');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('client_id')->references('id')->on('clients')->cascadeOnDelete();
            $table->foreign('recipe_id')->references('id')->on('production_recipes')->cascadeOnDelete();

            $table->unique(['client_id', 'recipe_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_production');
    }
};
