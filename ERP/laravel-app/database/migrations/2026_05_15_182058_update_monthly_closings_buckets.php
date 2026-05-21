<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('monthly_closings', function (Blueprint $table) {
            $table->decimal('purchases_qty', 15, 3)->default(0)->after('opening_value');
            $table->decimal('purchases_value', 15, 2)->default(0)->after('purchases_qty');
            $table->decimal('internal_in_qty', 15, 3)->default(0)->after('purchases_value');
            $table->decimal('internal_out_qty', 15, 3)->default(0)->after('in_value');
            $table->decimal('consumption_qty', 15, 3)->default(0)->after('internal_out_qty');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('monthly_closings', function (Blueprint $table) {
            $table->dropColumn(['purchases_qty', 'purchases_value', 'internal_in_qty', 'internal_out_qty', 'consumption_qty']);
        });
    }
};
