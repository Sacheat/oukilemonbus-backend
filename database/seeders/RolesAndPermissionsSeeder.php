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
            'organizations.ban',
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
            'tooling.access',
            'users.manage-roles',
            'users.send-password-reset',
            'dashboard.view',
            'trip-reports.submit',
            'trip-reports.manage',
            'trip-progress.record',
            'trip-progress.manage',
            'driver-alerts.create',
            'driver-alerts.manage',
        ];

        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $admin = Role::findOrCreate('admin', 'web');
        $user = Role::findOrCreate('user', 'web');
        $chauffeur = Role::findOrCreate('chauffeur', 'web');
        $agentCommunal = Role::findOrCreate('agent_communal', 'web');

        $this->removeLegacyResponsableRole($user);

        // admin = toutes les permissions sans exception.
        // Réservé aux développeurs et au support applicatif, pas aux agents communaux.
        // Permissions exclusives à admin (non accordées à agent_communal) :
        //   - users.create        : la création de comptes passe par l'inscription, pas par un agent
        //   - users.update-status : modération des comptes (bloquer/débloquer), rôle support
        //   - users.deactivate    : désactivation définitive d'un compte, action destructrice irréversible
        //   - notifications.monitor : health check système, outil de support/debug
        //   - tooling.access        : module Tooling (Backend Inspector, Dev Toolbox en lecture)
        //   - users.manage-roles    : attribution des rôles globaux
        //   - users.send-password-reset : envoi d'un lien de réinitialisation du mot de passe à un compte
        // syncPermissions : chaque rôle a EXACTEMENT la liste ci-dessous, y compris après un re-seed
        // (givePermissionTo ne faisait qu'ajouter et laissait subsister d'anciennes permissions).
        $admin->syncPermissions($permissions);

        $user->syncPermissions([
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

        $chauffeur->syncPermissions([
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
            // Compte rendu de ses trajets (réalisé / non réalisé), dans la fenêtre prévue (TripReportService)
            'trip-reports.submit',
            // « Ma journée » : étapes de ses trajets (TripProgressService) et demande d'aide (DriverAlertService)
            'trip-progress.record',
            'driver-alerts.create',
        ]);

        // agent_communal = rôle opérationnel complet pour les agents de la commune.
        // Gère tout le flux métier du bus : réservations, planning, documents, organisations,
        // décisions du conseil, indisponibilités, chauffeurs et véhicules.
        // Ne peut pas créer/modérer des comptes ni accéder aux outils système (réservés à admin).
        // Si une nouvelle permission est ajoutée, vérifier si elle est métier (ici) ou système (admin).
        $agentCommunal->syncPermissions([
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
            'dashboard.view',
            // Voir et corriger les comptes rendus de trajet, sans limite de délai
            'trip-reports.manage',
            // Suivre l'avancement des trajets et traiter les demandes d'aide des chauffeurs
            'trip-progress.manage',
            'driver-alerts.manage',
        ]);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Le rôle global responsable_organisation n'existe plus : être responsable dépend désormais
     * de la relation avec chaque organisation (UserOrganization::MANAGER_TYPES).
     * Ses détenteurs repassent au rôle user avant suppression. Sans effet si le rôle est déjà absent.
     */
    private function removeLegacyResponsableRole(Role $userRole): void
    {
        $legacy = Role::query()->where('name', 'responsable_organisation')->where('guard_name', 'web')->first();

        if ($legacy === null) {
            return;
        }

        foreach ($legacy->users()->get() as $member) {
            $member->removeRole($legacy);
            $member->assignRole($userRole);
        }

        $legacy->delete();
    }
}
