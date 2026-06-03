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
            'council-decisions.view',
            'council-decisions.create',
            'council-decisions.update',
            'documents.view',
            'documents.download',
            'documents.generate',
            'documents.update',
            'notifications.monitor',
            'recurrences.cancel',
        ];

        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $admin = Role::findOrCreate('admin', 'web');
        $user = Role::findOrCreate('user', 'web');
        $chauffeur = Role::findOrCreate('chauffeur', 'web');
        $agentCommunal = Role::findOrCreate('agent_communal', 'web');
        $responsableOrganisation = Role::findOrCreate('responsable_organisation', 'web');

        // admin = toutes les permissions sans exception.
        // Réservé aux développeurs et au support applicatif, pas aux agents communaux.
        // Permissions exclusives à admin (non accordées à agent_communal) :
        //   - users.create        : la création de comptes passe par l'inscription, pas par un agent
        //   - users.update-status : modération des comptes (bloquer/débloquer), rôle support
        //   - users.deactivate    : désactivation définitive d'un compte, action destructrice irréversible
        //   - notifications.monitor : health check système, outil de support/debug
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
            'council-decisions.view',
            'reservations.view',
            'reservations.create',
            'reservations.update',
            'reservations.cancel',
            'recurrences.cancel',
            'documents.view',
            'documents.download',
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
            'council-decisions.view',
            'unavailabilities.view',
            'reservations.view',
            'documents.view',
            'documents.download',
        ]);

        // agent_communal = rôle opérationnel complet pour les agents de la commune.
        // Gère tout le flux métier du bus : réservations, planning, documents, organisations,
        // décisions du conseil, indisponibilités, chauffeurs et véhicules.
        // Ne peut pas créer/modérer des comptes ni accéder aux outils système (réservés à admin).
        // Si une nouvelle permission est ajoutée, vérifier si elle est métier (ici) ou système (admin).
        $agentCommunal->givePermissionTo([
            'users.view',
            'users.update',
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
            'recurrences.cancel',
            'council-decisions.view',
            'council-decisions.create',
            'council-decisions.update',
            'documents.view',
            'documents.download',
            'documents.generate',
            'documents.update',
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
            'council-decisions.view',
            'reservations.view',
            'reservations.create',
            'reservations.update',
            'reservations.cancel',
            'recurrences.cancel',
            'documents.view',
            'documents.download',
        ]);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
