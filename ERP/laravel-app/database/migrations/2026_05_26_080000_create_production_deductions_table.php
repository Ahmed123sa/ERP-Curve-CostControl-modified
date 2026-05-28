<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('production_deductions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('client_id');
            $table->uuid('recipe_id');
            $table->string('month', 7);
            $table->boolean('deduct')->default(false);
            $table->timestamps();

            $table->unique(['client_id', 'recipe_id', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_deductions');
    }
};
