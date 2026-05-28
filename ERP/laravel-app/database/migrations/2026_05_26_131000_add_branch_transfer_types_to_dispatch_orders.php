<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE dispatch_orders MODIFY COLUMN type ENUM('purchase', 'dispatch', 'transfer', 'withdrawal', 'production', 'external_sale', 'opening', 'final', 'adjustment', 'return', 'branch_transfer', 'branch_return') DEFAULT 'purchase'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE dispatch_orders MODIFY COLUMN type ENUM('purchase', 'dispatch', 'transfer', 'withdrawal', 'production', 'external_sale', 'opening', 'final', 'adjustment', 'return') DEFAULT 'purchase'");
    }
};
