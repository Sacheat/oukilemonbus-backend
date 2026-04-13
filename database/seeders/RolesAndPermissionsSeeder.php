<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permissions = [
            'users.view',
            'users.create',
            'users.update',
            'users.update-status',
            'users.deactivate',
        ];

        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $admin = Role::findOrCreate('admin', 'web');
        $user = Role::findOrCreate('user', 'web');
        $chauffeur = Role::findOrCreate('chauffeur', 'web');
        $agentCommunal = Role::findOrCreate('agent_communal', 'web');
        $responsableOrganisation = Role::findOrCreate('responsable_organisation', 'web');

        $admin->givePermissionTo($permissions);

        $user->givePermissionTo([
            'users.view',
        ]);

        $chauffeur->givePermissionTo([
            'users.view',
        ]);

        $agentCommunal->givePermissionTo([
            'users.view',
            'users.create',
            'users.update',
            'users.update-status',
        ]);

        $responsableOrganisation->givePermissionTo([
            'users.view',
        ]);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
