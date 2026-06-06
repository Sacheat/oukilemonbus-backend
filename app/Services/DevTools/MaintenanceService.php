<?php

declare(strict_types=1);

namespace App\Services\DevTools;

use Illuminate\Console\Command;
use Throwable;

final class MaintenanceService
{
    /**
     * Réinitialise le cache des permissions, re-seede les rôles et vide les caches Laravel.
     * Passe la commande pour que la sortie des sous-commandes apparaisse dans le terminal.
     */
    public function repairPermissions(Command $command): array
    {
        $seeder = (string) config('dev_toolbox.repair_permissions.seeder', 'RolesAndPermissionsSeeder');

        $command->call('permission:cache-reset');
        $command->call('db:seed', ['--class' => $seeder, '--force' => true]);
        $command->call('optimize:clear');

        return ['seeder' => $seeder];
    }

    /**
     * Vide tous les caches Laravel (config, cache applicatif, vues compilées, routes).
     */
    public function clearCaches(Command $command): void
    {
        foreach (['config:clear', 'cache:clear', 'view:clear', 'route:clear'] as $cmd) {
            $command->call($cmd);
        }
    }

    /**
     * Marque les emails des comptes fictifs comme vérifiés.
     * Retourne le nombre de comptes mis à jour.
     */
    public function validateDemoEmails(): int
    {
        $pattern   = (string) config('dev_toolbox.demo.email_pattern', '.test@');
        $userModel = config('auth.providers.users.model', \App\Models\User::class);

        try {
            return $userModel::whereNull('email_verified_at')
                ->where('email', 'like', '%' . $pattern . '%')
                ->update(['email_verified_at' => now()]);
        } catch (Throwable) {
            return 0;
        }
    }
}
