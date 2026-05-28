<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dispatch_lines', function (Blueprint $table) {
            $table->string('source_name')->nullable()->after('date');
        });

        Schema::table('stock_ledger', function (Blueprint $table) {
            $table->foreignUuid('dispatch_line_id')->nullable()->constrained('dispatch_lines')->nullOnDelete()->after('ref_id');
        });
    }

    public function down(): void
    {
        Schema::table('dispatch_lines', function (Blueprint $table) {
            $table->dropColumn('source_name');
        });

        Schema::table('stock_ledger', function (Blueprint $table) {
            $table->dropForeign(['dispatch_line_id']);
            $table->dropColumn('dispatch_line_id');
        });
    }
};
