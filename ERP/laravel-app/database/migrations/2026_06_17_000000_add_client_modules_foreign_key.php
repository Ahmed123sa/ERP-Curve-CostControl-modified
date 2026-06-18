<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_modules', function (Blueprint $table) {
            $table->foreign('client_id')
                  ->references('id')
                  ->on('clients')
                  ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('client_modules', function (Blueprint $table) {
            $table->dropForeign(['client_id']);
        });
    }
};
