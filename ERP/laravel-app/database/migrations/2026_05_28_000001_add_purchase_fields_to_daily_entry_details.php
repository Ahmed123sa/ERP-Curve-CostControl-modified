<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('financial_daily_entry_details', function (Blueprint $table) {
            $table->decimal('quantity', 12, 3)->nullable()->after('amount');
            $table->boolean('is_purchase')->default(false)->after('description');
            $table->uuid('item_id')->nullable()->after('is_purchase');
            $table->foreign('item_id')->references('id')->on('items')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('financial_daily_entry_details', function (Blueprint $table) {
            $table->dropForeign(['item_id']);
            $table->dropColumn(['quantity', 'is_purchase', 'item_id']);
        });
    }
};
