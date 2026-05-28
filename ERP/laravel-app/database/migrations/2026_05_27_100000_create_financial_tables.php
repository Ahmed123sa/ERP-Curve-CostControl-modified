<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_expense_categories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('client_id')->nullable();
            $table->string('name');
            $table->string('code')->nullable();
            $table->unsignedTinyInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('financial_daily_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('client_id');
            $table->date('date');
            $table->decimal('total_sales', 12, 3)->default(0);
            $table->decimal('total_expenses', 12, 3)->default(0);
            $table->decimal('net_daily', 12, 3)->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['client_id', 'date']);
        });

        Schema::create('financial_daily_entry_details', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('client_id');
            $table->uuid('daily_entry_id');
            $table->uuid('expense_category_id');
            $table->decimal('amount', 12, 3)->default(0);
            $table->string('description')->nullable();
            $table->timestamps();

            $table->foreign('daily_entry_id')->references('id')->on('financial_daily_entries')->cascadeOnDelete();
            $table->foreign('expense_category_id')->references('id')->on('financial_expense_categories');
        });

        Schema::create('financial_monthly_summaries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('client_id');
            $table->unsignedSmallInteger('month');
            $table->unsignedSmallInteger('year');
            $table->decimal('total_sales', 14, 3)->default(0);
            $table->decimal('total_expenses', 14, 3)->default(0);
            $table->decimal('net_total', 14, 3)->default(0);
            $table->string('status')->default('draft'); // draft, finalized
            $table->timestamps();

            $table->unique(['client_id', 'month', 'year']);
        });

        Schema::create('financial_monthly_summary_details', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('client_id');
            $table->uuid('summary_id');
            $table->uuid('expense_category_id');
            $table->decimal('total_amount', 14, 3)->default(0);
            $table->timestamps();

            $table->foreign('summary_id')->references('id')->on('financial_monthly_summaries')->cascadeOnDelete();
            $table->foreign('expense_category_id')->references('id')->on('financial_expense_categories');
        });

        Schema::create('financial_closing_reports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('client_id');
            $table->unsignedSmallInteger('month');
            $table->unsignedSmallInteger('year');
            $table->decimal('total_sales', 14, 3)->default(0);
            $table->decimal('total_purchases', 14, 3)->default(0);
            $table->decimal('total_expenses', 14, 3)->default(0);
            $table->decimal('net_cash_profit', 14, 3)->default(0);
            $table->decimal('net_profit', 14, 3)->default(0);
            $table->json('percentages_json')->nullable();
            $table->string('status')->default('draft');
            $table->timestamps();

            $table->unique(['client_id', 'month', 'year']);
        });

        Schema::create('financial_closing_report_details', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('client_id');
            $table->uuid('closing_report_id');
            $table->string('line_type'); // expense, purchase, profit, revenue
            $table->string('name');
            $table->decimal('amount', 14, 3)->default(0);
            $table->decimal('percentage', 8, 4)->default(0);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->foreign('closing_report_id')->references('id')->on('financial_closing_reports')->cascadeOnDelete();
        });

        Schema::create('financial_employees', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('client_id');
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('employee_advances', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('client_id');
            $table->uuid('employee_id');
            $table->date('date');
            $table->decimal('amount', 12, 3)->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('employee_id')->references('id')->on('financial_employees')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_advances');
        Schema::dropIfExists('financial_employees');
        Schema::dropIfExists('financial_closing_report_details');
        Schema::dropIfExists('financial_closing_reports');
        Schema::dropIfExists('financial_monthly_summary_details');
        Schema::dropIfExists('financial_monthly_summaries');
        Schema::dropIfExists('financial_daily_entry_details');
        Schema::dropIfExists('financial_daily_entries');
        Schema::dropIfExists('financial_expense_categories');
    }
};
