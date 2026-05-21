<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // 1. حذف الـ dispatch_lines اللي warehouse_id = null
        DB::table('dispatch_lines')
            ->whereNull('warehouse_id')
            ->delete();

        // 2. حذف الـ dispatch_orders اللي ما عندهاش أي lines
        DB::table('dispatch_orders')
            ->whereNotIn('id', function ($query) {
                $query->select('order_id')->from('dispatch_lines');
            })
            ->delete();

        // 3. حذف الـ stock_ledger entries اللي warehouse_id = null
        DB::table('stock_ledger')
            ->whereNull('warehouse_id')
            ->delete();

        // 4. حذف الـ monthly_closings القديمة (خلاص بنعيد التقفيل من الصفر)
        DB::table('monthly_closings')->truncate();
    }

    public function down(): void
    {
        // مفيش rollback — البيانات اتمسحت بإرادتنا
    }
};