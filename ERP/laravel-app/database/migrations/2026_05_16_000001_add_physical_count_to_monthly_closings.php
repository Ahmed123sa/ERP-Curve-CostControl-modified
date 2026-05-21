<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monthly_closings', function (Blueprint $table) {
            $table->decimal('physical_count', 12, 3)->nullable()->after('closing_qty_actual');
        });
    }

    public function down(): void
    {
        Schema::table('monthly_closings', function (Blueprint $table) {
            $table->dropColumn('physical_count');
        });
    }
};
