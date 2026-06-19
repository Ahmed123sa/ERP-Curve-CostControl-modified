<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // For MySQL - modify enum
        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE dispatch_orders MODIFY COLUMN type ENUM('purchase', 'dispatch', 'transfer', 'withdrawal', 'production', 'external_sale', 'opening', 'final') DEFAULT 'purchase'");
        } elseif ($driver === 'pgsql') {
            DB::statement("ALTER TYPE dispatch_order_type ADD VALUE IF NOT EXISTS 'opening'");
            DB::statement("ALTER TYPE dispatch_order_type ADD VALUE IF NOT EXISTS 'final'");
        }
        // SQLite: no ENUM support — column is TEXT by default, no change needed
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE dispatch_orders MODIFY COLUMN type ENUM('purchase', 'dispatch', 'transfer', 'withdrawal', 'production', 'external_sale') DEFAULT 'purchase'");
        }
        // SQLite & pgsql: no-op
    }
};