<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\StaffPermission;
use App\Enums\UserRole;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

final class RolesSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (StaffPermission::cases() as $permission) {
            Permission::findOrCreate($permission->value, 'web');
        }

        foreach (UserRole::cases() as $role) {
            $model = Role::findOrCreate($role->value, 'web');
            $model->syncPermissions(StaffPermission::valuesFor($role));
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}