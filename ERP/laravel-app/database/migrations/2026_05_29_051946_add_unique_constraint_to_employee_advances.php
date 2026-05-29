<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Remove duplicates: keep the row with the smallest id for each (client_id, employee_id, date)
        DB::statement("
            DELETE ea1 FROM employee_advances ea1
            INNER JOIN employee_advances ea2
            ON ea1.client_id = ea2.client_id
            AND ea1.employee_id = ea2.employee_id
            AND ea1.date = ea2.date
            AND ea1.id > ea2.id
        ");

        Schema::table('employee_advances', function (Blueprint $table) {
            $table->unique(['client_id', 'employee_id', 'date'], 'adv_unique');
        });
    }

    public function down(): void
    {
        Schema::table('employee_advances', function (Blueprint $table) {
            $table->dropUnique('adv_unique');
        });
    }
};
