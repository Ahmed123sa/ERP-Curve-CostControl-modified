<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('menu_import_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('client_id');
            $table->string('branch_id');
            $table->date('sale_date');
            $table->string('file_name')->nullable();
            $table->integer('total_rows')->default(0);
            $table->string('status', 20)->default('pending');
            $table->json('half_categories')->nullable();
            $table->timestamps();
            $table->timestamp('expires_at');
            $table->foreign('client_id')->references('id')->on('clients');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('menu_import_sessions');
    }
};
