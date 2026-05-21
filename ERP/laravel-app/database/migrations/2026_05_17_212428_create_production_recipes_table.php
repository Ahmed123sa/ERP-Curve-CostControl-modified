<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('production_recipes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('client_id');
            $table->uuid('item_id')->comment('المنتج النهائي');
            $table->string('name');
            $table->string('unit', 50)->nullable()->comment('وحدة البورشن');
            $table->decimal('qty_per_portion', 12, 4)->default(1)->comment('كمية المنتج لكل بورشن');
            $table->uuid('output_warehouse_id')->nullable()->comment('المخزن المستلم');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('client_id')->references('id')->on('clients')->cascadeOnDelete();
            $table->foreign('item_id')->references('id')->on('items')->cascadeOnDelete();
            $table->foreign('output_warehouse_id')->references('id')->on('warehouses')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_recipes');
    }
};
