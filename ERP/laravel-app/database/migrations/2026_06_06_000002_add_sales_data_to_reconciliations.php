<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('menu_engineering_reconciliations', function (Blueprint $table) {
            $table->json('sales_data')->nullable()->after('to_date');
        });
    }

    public function down(): void
    {
        Schema::table('menu_engineering_reconciliations', function (Blueprint $table) {
            $table->dropColumn('sales_data');
        });
    }
};
