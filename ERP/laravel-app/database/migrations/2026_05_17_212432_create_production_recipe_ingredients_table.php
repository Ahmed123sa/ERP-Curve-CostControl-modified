<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('production_recipe_ingredients', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('recipe_id');
            $table->uuid('item_id')->comment('المادة الخام');
            $table->decimal('qty', 12, 4)->comment('الكمية لكل بورشن');
            $table->decimal('unit_cost', 12, 4)->nullable()->comment('سعر الوحدة عند إعداد الوصفة');
            $table->timestamps();

            $table->foreign('recipe_id')->references('id')->on('production_recipes')->cascadeOnDelete();
            $table->foreign('item_id')->references('id')->on('items')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_recipe_ingredients');
    }
};
