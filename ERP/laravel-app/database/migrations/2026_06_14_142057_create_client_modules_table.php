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
        Schema::create('client_modules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('client_id', 36);
            $table->string('module_key', 100);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['client_id', 'module_key']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('client_modules');
    }
};
