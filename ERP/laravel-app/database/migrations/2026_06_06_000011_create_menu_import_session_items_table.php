<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('menu_import_session_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('session_id');
            $table->integer('row_index');
            $table->string('source_name');
            $table->decimal('qty_sold', 12, 2);
            $table->string('category')->nullable();
            $table->string('size')->nullable();
            $table->uuid('recipe_id')->nullable();
            $table->string('recipe_name')->nullable();
            $table->string('status', 20)->default('unmatched');
            $table->integer('confidence')->default(0);
            $table->timestamps();
            $table->foreign('session_id')->references('id')->on('menu_import_sessions')->onDelete('cascade');
            $table->foreign('recipe_id')->references('id')->on('menu_engineering_recipes')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('menu_import_session_items');
    }
};
