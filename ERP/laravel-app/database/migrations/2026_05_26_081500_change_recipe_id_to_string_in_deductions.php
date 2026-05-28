<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_deductions', function (Blueprint $table) {
            $table->string('recipe_id', 100)->change();
        });
    }

    public function down(): void
    {
        // cannot reliably revert string→uuid
    }
};
