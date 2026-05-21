<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monthly_closings', function (Blueprint $table) {
            $table->json('branch_dispatches')->nullable()->after('out_qty');
        });
    }

    public function down(): void
    {
        Schema::table('monthly_closings', function (Blueprint $table) {
            $table->dropColumn('branch_dispatches');
        });
    }
};
