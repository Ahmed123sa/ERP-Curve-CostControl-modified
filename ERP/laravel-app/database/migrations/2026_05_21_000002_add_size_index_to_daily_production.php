<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('daily_production', function (Blueprint $table) {
            $table->unsignedTinyInteger('size_index')->nullable()->after('recipe_id')
                ->comment('Index in recipe sizes array, null for base recipe');
        });

        Schema::table('daily_production', function (Blueprint $table) {
            $table->dropUnique(['client_id', 'recipe_id', 'date']);
        });

        Schema::table('daily_production', function (Blueprint $table) {
            $table->unique(['client_id', 'recipe_id', 'date', 'size_index'], 'dp_unique_recipe_date_size');
        });
    }

    public function down(): void
    {
        Schema::table('daily_production', function (Blueprint $table) {
            $table->dropUnique('dp_unique_recipe_date_size');
        });

        Schema::table('daily_production', function (Blueprint $table) {
            $table->unique(['client_id', 'recipe_id', 'date']);
        });

        Schema::table('daily_production', function (Blueprint $table) {
            $table->dropColumn('size_index');
        });
    }
};
