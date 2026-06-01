<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_monthly', function (Blueprint $table) {
            $table->unsignedTinyInteger('salary_base_days')->default(30)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('payroll_monthly', function (Blueprint $table) {
            $table->dropColumn('salary_base_days');
        });
    }
};
