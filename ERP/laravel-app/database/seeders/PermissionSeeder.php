<?php
namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        // Reset cached roles and permissions
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        $modules = [
            'dashboard',
            'vouchers.purchase', 'vouchers.dispatch', 'vouchers.upload', 'vouchers.history',
            'closing',
            'clients',
            'production',
            'stock.current', 'stock.movement', 'stock.opening', 'stock.closing',
            'menu-engineering',
            'reports.financial', 'reports.grand-summary', 'reports.diffs', 'reports.food-cost',
            'items',
            'warehouses',
            'mappings',
            'users',
            'settings',
        ];

        foreach ($modules as $module) {
            Permission::create(['name' => $module, 'guard_name' => 'web']);
        }

        // Super Admin — all permissions
        $superAdmin = Role::create(['name' => 'super-admin', 'guard_name' => 'web']);
        $superAdmin->givePermissionTo(Permission::all());

        // Cost Controller — core operational modules
        $controller = Role::create(['name' => 'cost-controller', 'guard_name' => 'web']);
        $controller->givePermissionTo([
            'dashboard', 'vouchers.purchase', 'vouchers.dispatch', 'vouchers.upload', 'vouchers.history',
            'closing', 'stock.current', 'stock.movement', 'stock.opening', 'stock.closing',
            'production', 'menu-engineering',
            'reports.financial', 'reports.grand-summary', 'reports.diffs', 'reports.food-cost',
            'items', 'warehouses', 'mappings',
        ]);

        // Viewer — read-only
        $viewer = Role::create(['name' => 'viewer', 'guard_name' => 'web']);
        $viewer->givePermissionTo([
            'dashboard', 'stock.current', 'production', 'menu-engineering',
            'reports.financial', 'reports.grand-summary', 'reports.diffs', 'reports.food-cost',
        ]);
    }
}
