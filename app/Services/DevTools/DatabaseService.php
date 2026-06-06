<?php

declare(strict_types=1);

namespace App\Services\DevTools;

use Illuminate\Console\Command;

final class DatabaseService
{
    /**
     * Remet la base à zéro et re-seede complètement la démo.
     * La confirmation est gérée dans la commande avant d'appeler cette méthode.
     */
    public function resetDemo(Command $command): void
    {
        $seeders = (array) config('dev_toolbox.reset_demo.seeders', ['DatabaseSeeder']);

        $command->call('migrate:fresh', ['--force' => true]);

        foreach ($seeders as $seeder) {
            $command->call('db:seed', ['--class' => $seeder, '--force' => true]);
        }
    }
}
