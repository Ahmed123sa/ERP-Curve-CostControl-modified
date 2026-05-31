<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddApprovalColumnsToFinancialClosingReports extends Migration
{
    public function up(): void
    {
        Schema::table('financial_closing_reports', function (Blueprint $table) {
            $table->string('approved_by', 36)->nullable()->after('status');
            $table->timestamp('approved_at')->nullable()->after('approved_by');
            $table->string('closed_by', 36)->nullable()->after('approved_at');
            $table->timestamp('closed_at')->nullable()->after('closed_by');
        });
    }

    public function down(): void
    {
        Schema::table('financial_closing_reports', function (Blueprint $table) {
            $table->dropColumn(['approved_by', 'approved_at', 'closed_by', 'closed_at']);
        });
    }
}
