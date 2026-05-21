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
        Schema::table('production_recipes', function (Blueprint $table) {
            $table->decimal('production_qty', 12, 4)->default(1)->after('qty_per_portion')->comment('كمية الإنتاج');
            $table->decimal('selling_price', 12, 4)->nullable()->after('production_qty')->comment('سعر البيع');
        });
    }

    public function down(): void
    {
        Schema::table('production_recipes', function (Blueprint $table) {
            $table->dropColumn(['production_qty', 'selling_price']);
        });
    }
};
