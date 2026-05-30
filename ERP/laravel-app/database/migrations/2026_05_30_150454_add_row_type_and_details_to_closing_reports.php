<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('financial_closing_report_details', function (Blueprint $table) {
            $table->string('row_type')->default('auto')->after('line_type');
            $table->json('formula_config')->nullable()->after('percentage');
            $table->uuid('parent_id')->nullable()->after('formula_config');
            $table->uuid('category_id')->nullable()->after('parent_id');

            $table->foreign('parent_id')->references('id')->on('financial_closing_report_details')->cascadeOnDelete();
            $table->foreign('category_id')->references('id')->on('financial_expense_categories')->nullOnDelete();
        });

        Schema::create('financial_closing_report_detail_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('client_id');
            $table->uuid('closing_report_id');
            $table->uuid('detail_id');
            $table->string('name');
            $table->decimal('amount', 14, 3)->default(0);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->foreign('closing_report_id')->references('id')->on('financial_closing_reports')->cascadeOnDelete();
            $table->foreign('detail_id')->references('id')->on('financial_closing_report_details')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_closing_report_detail_items');

        Schema::table('financial_closing_report_details', function (Blueprint $table) {
            $table->dropForeign(['parent_id']);
            $table->dropForeign(['category_id']);
            $table->dropColumn(['row_type', 'formula_config', 'parent_id', 'category_id']);
        });
    }
};
