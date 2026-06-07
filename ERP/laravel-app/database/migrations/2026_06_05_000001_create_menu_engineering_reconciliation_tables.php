<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('menu_engineering_reconciliations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('client_id');
            $table->uuid('branch_id');
            $table->date('from_date');
            $table->date('to_date');
            $table->timestamps();

            $table->index('client_id');
            $table->index('branch_id');
        });

        Schema::create('menu_engineering_reconciliation_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('reconciliation_id');
            $table->uuid('ingredient_id');
            $table->string('ingredient_name');
            $table->string('unit')->nullable();
            $table->decimal('opening_qty', 15, 4)->default(0);
            $table->decimal('purchases_qty', 15, 4)->default(0);
            $table->decimal('closing_actual', 15, 4)->default(0);
            $table->decimal('actual_received', 15, 4)->default(0);
            $table->decimal('sales_qty', 15, 4)->default(0);
            $table->decimal('waste_qty', 15, 4)->default(0);
            $table->decimal('diff_qty', 15, 4)->default(0);
            $table->timestamps();

            $table->foreign('reconciliation_id')
                ->references('id')
                ->on('menu_engineering_reconciliations')
                ->onDelete('cascade');

            $table->index('ingredient_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('menu_engineering_reconciliation_items');
        Schema::dropIfExists('menu_engineering_reconciliations');
    }
};
