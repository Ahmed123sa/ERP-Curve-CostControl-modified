<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('menu_engineering_sales', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('client_id');
            $table->uuid('branch_id');
            $table->uuid('recipe_id');
            $table->decimal('qty_sold', 12, 2);
            $table->decimal('selling_price', 15, 4)->nullable();
            $table->date('sale_date');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('client_id');
            $table->index('branch_id');
            $table->index('recipe_id');
            $table->index('sale_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('menu_engineering_sales');
    }
};
