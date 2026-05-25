<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->dropColumn(['max_stock_level', 'reorder_qty']);
        });
    }

    public function down(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->decimal('max_stock_level', 12, 2)->nullable()->after('min_stock_level');
            $table->decimal('reorder_qty', 12, 2)->nullable()->after('max_stock_level');
        });
    }
};
