<?php

namespace Database\Seeders;

use App\Models\Financial\FinancialExpenseCategory;
use Illuminate\Database\Seeder;

class FinancialCategorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            ['name' => 'مصروفات عامة', 'code' => 'general_expenses', 'sort_order' => 1],
            ['name' => 'صيانة', 'code' => 'maintenance', 'sort_order' => 2],
            ['name' => 'كهرباء', 'code' => 'electricity', 'sort_order' => 3],
            ['name' => 'جنيرال', 'code' => 'general', 'sort_order' => 4],
            ['name' => 'أصول', 'code' => 'assets', 'sort_order' => 5],
            ['name' => 'رواتب-سلف', 'code' => 'salaries_advances', 'sort_order' => 6],
            ['name' => 'مشتريات بار', 'code' => 'bar_purchases', 'sort_order' => 7],
            ['name' => 'مشتريات مطبخ', 'code' => 'kitchen_purchases', 'sort_order' => 8],
            ['name' => 'مشتريات شيشة', 'code' => 'shisha_purchases', 'sort_order' => 9],
            ['name' => 'فيزا', 'code' => 'visa', 'sort_order' => 10],
            ['name' => 'مياه', 'code' => 'water', 'sort_order' => 11],
            ['name' => 'فواتير أخرى', 'code' => 'other_bills', 'sort_order' => 12],
            ['name' => 'صالة', 'code' => 'hall', 'sort_order' => 13],
            ['name' => 'ضيافة', 'code' => 'hospitality', 'sort_order' => 14],
        ];

        foreach ($categories as $cat) {
            FinancialExpenseCategory::updateOrCreate(
                ['code' => $cat['code']],
                ['name' => $cat['name'], 'sort_order' => $cat['sort_order'], 'client_id' => null]
            );
        }
    }
}
