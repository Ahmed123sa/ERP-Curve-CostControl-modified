<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('slaughters', function (Blueprint $table) {
            $table->string('animal_name', 255)->nullable()->after('date');
        });
    }

    public function down(): void
    {
        Schema::table('slaughters', function (Blueprint $table) {
            $table->dropColumn('animal_name');
        });
    }
};
