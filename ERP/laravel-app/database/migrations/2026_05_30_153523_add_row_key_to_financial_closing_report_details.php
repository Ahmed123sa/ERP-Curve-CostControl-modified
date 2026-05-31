<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddRowKeyToFinancialClosingReportDetails extends Migration
{
    public function up(): void
    {
        Schema::table('financial_closing_report_details', function (Blueprint $table) {
            $table->string('row_key', 100)->nullable()->after('id');
        });
    }

    public function down(): void
    {
        Schema::table('financial_closing_report_details', function (Blueprint $table) {
            $table->dropColumn('row_key');
        });
    }
}
