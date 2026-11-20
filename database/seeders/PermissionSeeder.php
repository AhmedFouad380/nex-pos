<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/** Syncs config/permissions.php into the DB. Adds/updates only — never strips a client's custom grants. */
class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $all = collect(config('permissions.groups'))->flatten();

        foreach ($all as $name) {
            Permission::findOrCreate($name, 'web');
        }

        foreach (config('permissions.roles') as $roleName => $permissions) {
            $role = Role::findOrCreate($roleName, 'web');

            if ($roleName === 'super-admin') {
                continue; // bypasses every check via Gate::before
            }

            $role->givePermissionTo($permissions === ['*'] ? $all->all() : $permissions);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
