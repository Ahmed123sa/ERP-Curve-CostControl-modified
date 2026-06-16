<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE `users` MODIFY `role` ENUM('admin','cost_controller','viewer','client') NOT NULL DEFAULT 'cost_controller'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE `users` MODIFY `role` ENUM('admin','cost_controller','viewer') NOT NULL DEFAULT 'cost_controller'");
    }
};
