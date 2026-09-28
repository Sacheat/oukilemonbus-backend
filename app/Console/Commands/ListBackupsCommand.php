<?php

namespace App\Console\Commands;

use App\Services\Backups\BackupService;
use Illuminate\Console\Command;

/**
 * Affiche la liste des backups disponibles dans le répertoire de stockage configuré.
 * Utile pour vérifier qu'un backup récent existe avant une migration ou une mise à jour.
 */
class ListBackupsCommand extends Command
{
    protected $signature = 'backup:list';

    protected $description = 'Liste les backups disponibles.';

    public function handle(BackupService $backupService): int
    {
        $backups = $backupService->list();

        if ($backups === []) {
            $this->info('Aucun backup disponible.');

            return self::SUCCESS;
        }

        $this->table(
            ['Nom', 'Taille', 'Créé le', 'Chemin'],
            collect($backups)->map(fn (array $backup): array => [
                $backup['name'],
                $this->formatBytes((int) $backup['size']),
                $backup['created_at'],
                $backup['path'],
            ])->all()
        );

        return self::SUCCESS;
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes.' B';
        }

        if ($bytes < 1048576) {
            return round($bytes / 1024, 2).' KB';
        }

        return round($bytes / 1048576, 2).' MB';
    }
}
