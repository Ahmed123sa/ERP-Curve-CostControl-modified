<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backup_settings', function (Blueprint $table) {
            $table->id();
            $table->string('local_path')->default(storage_path('backups'));
            $table->boolean('email_enabled')->default(false);
            $table->string('email_to')->nullable();
            $table->boolean('google_drive_enabled')->default(false);
            $table->text('google_drive_token')->nullable();
            $table->integer('retention_days')->default(7);
            $table->timestamps();
        });

        // Insert default settings row
        DB::table('backup_settings')->insert([
            'local_path' => storage_path('backups'),
            'email_enabled' => false,
            'retention_days' => 7,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_settings');
    }
};
