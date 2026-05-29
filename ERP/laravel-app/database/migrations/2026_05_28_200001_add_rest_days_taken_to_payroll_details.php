<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_monthly_details', function (Blueprint $table) {
            $table->unsignedTinyInteger('rest_days_taken')->default(0)->after('overtime_hours');
            $table->unsignedTinyInteger('double_shift_days')->default(0)->after('rest_day_ot_days');
            $table->decimal('double_shift_amount', 10, 2)->default(0)->after('double_shift_days');
        });
    }

    public function down(): void
    {
        Schema::table('payroll_monthly_details', function (Blueprint $table) {
            $table->dropColumn(['rest_days_taken', 'double_shift_days', 'double_shift_amount']);
        });
    }
};
