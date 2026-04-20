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
            'municipalities.view',
            'municipalities.manage',
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
            'unavailabilities.view',
            'unavailabilities.create',
            'unavailabilities.update',
            'unavailabilities.cancel',
            'reservations.view',
            'reservations.create',
            'reservations.update',
            'reservations.approve',
            'reservations.reject',
            'reservations.cancel',
            'reservations.assign',
            'reservations.confirm',
            'reservations.manage-conflicts',
            'reservations.record-council-decision',
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
            'municipalities.view',
            'org-registration-requests.view',
            'org-registration-requests.create',
            'user-org-requests.view',
            'user-org-requests.create',
            'organizations.view',
            'reservations.view',
            'reservations.create',
            'reservations.update',
            'reservations.cancel',
        ]);

        $chauffeur->givePermissionTo([
            'users.view',
            'locations.view',
            'municipalities.view',
            'vehicles.view',
            'drivers.view',
            'org-registration-requests.view',
            'org-registration-requests.create',
            'user-org-requests.view',
            'user-org-requests.create',
            'organizations.view',
            'unavailabilities.view',
            'reservations.view',
        ]);

        $agentCommunal->givePermissionTo([
            'users.view',
            'users.create',
            'users.update',
            'users.update-status',
            'locations.view',
            'locations.create',
            'locations.update',
            'municipalities.view',
            'municipalities.manage',
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
            'unavailabilities.view',
            'unavailabilities.create',
            'unavailabilities.update',
            'unavailabilities.cancel',
            'reservations.view',
            'reservations.create',
            'reservations.update',
            'reservations.approve',
            'reservations.reject',
            'reservations.cancel',
            'reservations.assign',
            'reservations.confirm',
            'reservations.manage-conflicts',
            'reservations.record-council-decision',
        ]);

        $responsableOrganisation->givePermissionTo([
            'users.view',
            'locations.view',
            'locations.create',
            'municipalities.view',
            'org-registration-requests.view',
            'org-registration-requests.create',
            'user-org-requests.view',
            'user-org-requests.create',
            'organizations.view',
            'reservations.view',
            'reservations.create',
            'reservations.update',
            'reservations.cancel',
        ]);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
