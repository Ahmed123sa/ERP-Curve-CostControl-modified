<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('financial_daily_entry_details', function (Blueprint $table) {
            $table->dropColumn('is_purchase');
        });
    }

    public function down(): void
    {
        Schema::table('financial_daily_entry_details', function (Blueprint $table) {
            $table->boolean('is_purchase')->default(false);
        });
    }
};
