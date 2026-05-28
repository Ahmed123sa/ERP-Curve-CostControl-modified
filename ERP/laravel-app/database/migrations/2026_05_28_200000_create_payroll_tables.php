<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_employees', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('client_id');
            $table->string('name');
            $table->string('job_title')->nullable();
            $table->decimal('base_salary', 10, 2)->default(0);
            $table->decimal('shift_hours', 4, 2)->default(9.00);
            $table->decimal('daily_wage', 10, 2)->default(0);
            $table->decimal('hourly_wage', 10, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->uuid('financial_employee_id')->nullable();
            $table->timestamps();
            $table->foreign('financial_employee_id')->references('id')->on('financial_employees')->nullOnDelete();
        });

        Schema::create('attendance_records', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('client_id');
            $table->uuid('employee_id');
            $table->date('date');
            $table->time('shift_start')->nullable();
            $table->time('shift_end')->nullable();
            $table->decimal('total_hours', 5, 2)->default(0);
            $table->decimal('overtime_minutes', 7, 2)->default(0);
            $table->boolean('is_double_shift')->default(false);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->foreign('employee_id')->references('id')->on('payroll_employees')->cascadeOnDelete();
            $table->unique(['client_id', 'employee_id', 'date']);
        });

        Schema::create('payroll_monthly', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('client_id');
            $table->unsignedSmallInteger('month');
            $table->unsignedSmallInteger('year');
            $table->string('status')->default('draft');
            $table->timestamps();
            $table->unique(['client_id', 'month', 'year']);
        });

        Schema::create('payroll_monthly_details', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('client_id');
            $table->uuid('payroll_id');
            $table->uuid('employee_id');
            $table->decimal('base_salary_snapshot', 10, 2)->default(0);
            $table->decimal('daily_wage_snapshot', 10, 2)->default(0);
            $table->decimal('hourly_wage_snapshot', 10, 2)->default(0);
            $table->unsignedTinyInteger('work_days')->default(0);
            $table->unsignedTinyInteger('absence_days')->default(0);
            $table->decimal('absence_amount', 10, 2)->default(0);
            $table->decimal('overtime_hours', 8, 2)->default(0);
            $table->decimal('overtime_amount', 10, 2)->default(0);
            $table->unsignedTinyInteger('rest_day_ot_days')->default(0);
            $table->decimal('rest_day_ot_amount', 10, 2)->default(0);
            $table->decimal('advance_amount', 10, 2)->default(0);
            $table->decimal('bonus_total', 10, 2)->default(0);
            $table->decimal('total_deductions', 10, 2)->default(0);
            $table->decimal('net_salary', 10, 2)->default(0);
            $table->timestamps();
            $table->foreign('payroll_id')->references('id')->on('payroll_monthly')->cascadeOnDelete();
            $table->foreign('employee_id')->references('id')->on('payroll_employees');
            $table->unique(['payroll_id', 'employee_id']);
        });

        Schema::create('payroll_bonus_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('client_id');
            $table->uuid('payroll_detail_id');
            $table->string('name');
            $table->decimal('amount', 10, 2)->default(0);
            $table->timestamps();
            $table->foreign('payroll_detail_id')->references('id')->on('payroll_monthly_details')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_bonus_items');
        Schema::dropIfExists('payroll_monthly_details');
        Schema::dropIfExists('payroll_monthly');
        Schema::dropIfExists('attendance_records');
        Schema::dropIfExists('payroll_employees');
    }
};
