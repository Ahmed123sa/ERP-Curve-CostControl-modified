<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('financial_expense_categories', function (Blueprint $table) {
            $table->uuid('client_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('financial_expense_categories', function (Blueprint $table) {
            $table->uuid('client_id')->nullable(false)->change();
        });
    }
};
