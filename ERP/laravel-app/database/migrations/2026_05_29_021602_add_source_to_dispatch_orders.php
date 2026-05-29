<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dispatch_orders', function (Blueprint $table) {
            $table->string('source')->default('upload')->after('status');
        });

        DB::table('dispatch_orders')->whereNull('source_file')->update(['source' => 'manual']);
    }

    public function down(): void
    {
        Schema::table('dispatch_orders', function (Blueprint $table) {
            $table->dropColumn('source');
        });
    }
};
