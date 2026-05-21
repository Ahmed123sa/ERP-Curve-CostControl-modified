<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('backup_settings', function (Blueprint $table) {
            $table->boolean('auto_backup_enabled')->default(false)->after('retention_days');
            $table->string('auto_backup_time')->default('03:00')->after('auto_backup_enabled');
            $table->string('auto_backup_frequency')->default('daily')->after('auto_backup_time');
            $table->string('auto_backup_days')->nullable()->after('auto_backup_frequency');
        });
    }

    public function down(): void
    {
        Schema::table('backup_settings', function (Blueprint $table) {
            $table->dropColumn(['auto_backup_enabled', 'auto_backup_time', 'auto_backup_frequency', 'auto_backup_days']);
        });
    }
};
