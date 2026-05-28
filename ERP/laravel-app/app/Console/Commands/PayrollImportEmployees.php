<?php

namespace App\Console\Commands;

use App\Models\Financial\FinancialEmployee;
use App\Models\Payroll\PayrollEmployee;
use Illuminate\Console\Command;

class PayrollImportEmployees extends Command
{
    protected $signature = 'payroll:import-employees';
    protected $description = 'Import existing financial_employees into payroll_employees';

    public function handle(): int
    {
        $financialEmployees = FinancialEmployee::allClients()->get();
        $imported = 0;
        $skipped = 0;

        foreach ($financialEmployees as $fe) {
            $exists = PayrollEmployee::where('client_id', $fe->client_id)
                ->where('name', $fe->name)
                ->exists();

            if ($exists) {
                $skipped++;
                continue;
            }

            PayrollEmployee::create([
                'client_id' => $fe->client_id,
                'name' => $fe->name,
                'base_salary' => 0,
                'shift_hours' => 9,
                'daily_wage' => 0,
                'hourly_wage' => 0,
                'is_active' => true,
                'financial_employee_id' => $fe->id,
            ]);

            $imported++;
        }

        $this->info("تم الاستيراد: {$imported} موظف، تم التخطي: {$skipped} موظف موجود بالفعل");
        return Command::SUCCESS;
    }
}
