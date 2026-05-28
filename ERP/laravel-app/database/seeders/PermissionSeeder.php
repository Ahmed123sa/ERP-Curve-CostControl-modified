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
            'financial.daily', 'financial.monthly', 'financial.closing', 'financial.advances',
        ];

        foreach ($modules as $module) {
            Permission::findOrCreate($module, 'web');
        }

        // Super Admin — all permissions
        $superAdmin = Role::findOrCreate('super-admin', 'web');
        $superAdmin->givePermissionTo(Permission::all());

        // Cost Controller — core operational modules
        $controller = Role::findOrCreate('cost-controller', 'web');
        $controller->givePermissionTo([
            'dashboard', 'vouchers.purchase', 'vouchers.dispatch', 'vouchers.upload', 'vouchers.history',
            'closing', 'stock.current', 'stock.movement', 'stock.opening', 'stock.closing',
            'production', 'menu-engineering',
            'reports.financial', 'reports.grand-summary', 'reports.diffs', 'reports.food-cost',
            'items', 'warehouses', 'mappings',
            'financial.daily', 'financial.monthly', 'financial.closing', 'financial.advances',
        ]);

        // Viewer — read-only
        $viewer = Role::findOrCreate('viewer', 'web');
        $viewer->givePermissionTo([
            'dashboard', 'stock.current', 'production', 'menu-engineering',
            'reports.financial', 'reports.grand-summary', 'reports.diffs', 'reports.food-cost',
            'financial.daily', 'financial.monthly', 'financial.closing',
        ]);
    }
}
