<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->uuid('linked_branch_id')->nullable()->after('default_cost');
            $table->foreign('linked_branch_id')->references('id')->on('branches')->nullOnDelete();
        });

        Schema::table('dispatch_orders', function (Blueprint $table) {
            $table->uuid('source_order_id')->nullable()->after('branch_id');
            $table->foreign('source_order_id')->references('id')->on('dispatch_orders')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->dropForeign(['linked_branch_id']);
            $table->dropColumn('linked_branch_id');
        });

        Schema::table('dispatch_orders', function (Blueprint $table) {
            $table->dropForeign(['source_order_id']);
            $table->dropColumn('source_order_id');
        });
    }
};
