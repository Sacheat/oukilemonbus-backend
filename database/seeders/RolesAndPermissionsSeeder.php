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
            'locations.view',
            'locations.create',
            'locations.update',
            'vehicles.view',
            'vehicles.create',
            'vehicles.update',
            'vehicles.deactivate',
            'drivers.view',
            'drivers.create',
            'drivers.update',
            'drivers.deactivate',
            'org-registration-requests.view',
            'org-registration-requests.create',
            'org-registration-requests.review',
            'user-org-requests.view',
            'user-org-requests.create',
            'user-org-requests.review',
            'organizations.view',
            'organizations.create',
            'organizations.update',
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
            'locations.view',
            'org-registration-requests.create',
            'user-org-requests.create',
            'organizations.view',
        ]);

        $chauffeur->givePermissionTo([
            'users.view',
            'locations.view',
            'vehicles.view',
            'drivers.view',
            'org-registration-requests.create',
            'user-org-requests.create',
            'organizations.view',
        ]);

        $agentCommunal->givePermissionTo([
            'users.view',
            'users.create',
            'users.update',
            'users.update-status',
            'locations.view',
            'locations.create',
            'locations.update',
            'vehicles.view',
            'vehicles.create',
            'vehicles.update',
            'vehicles.deactivate',
            'drivers.view',
            'drivers.create',
            'drivers.update',
            'drivers.deactivate',
            'org-registration-requests.view',
            'org-registration-requests.create',
            'org-registration-requests.review',
            'user-org-requests.view',
            'user-org-requests.create',
            'user-org-requests.review',
            'organizations.view',
            'organizations.create',
            'organizations.update',
        ]);

        $responsableOrganisation->givePermissionTo([
            'users.view',
            'locations.view',
            'locations.create',
            'org-registration-requests.create',
            'user-org-requests.create',
            'organizations.view',
        ]);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
