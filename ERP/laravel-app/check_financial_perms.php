<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make('Illuminate\Contracts\Console\Kernel');
$kernel->bootstrap();

$perms = \Spatie\Permission\Models\Permission::where('name', 'like', 'financial.%')->pluck('name');
echo 'Financial permissions: ' . $perms->implode(', ') . PHP_EOL;

$roles = \Spatie\Permission\Models\Role::all();
foreach ($roles as $role) {
    $has = $role->hasAnyPermission($perms->toArray());
    echo "Role '{$role->name}' has financial perms: " . ($has ? 'YES' : 'NO') . PHP_EOL;
}

// Also check what permissions super-admin actually has
$sa = \Spatie\Permission\Models\Role::where('name', 'super-admin')->first();
$saPerms = $sa->permissions()->pluck('name');
echo PHP_EOL . "Super-admin has " . $saPerms->count() . " permissions" . PHP_EOL;
echo "Includes financial.daily: " . ($saPerms->contains('financial.daily') ? 'YES' : 'NO') . PHP_EOL;
