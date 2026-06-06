<?php

namespace App\Console\Commands;

use App\Services\Backups\BackupService;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Throwable;

/**
 * Déclenche un backup manuel de la base de données et/ou des fichiers privés.
 * L'archive est stockée dans storage/app/private/backups/ et jamais exposée publiquement.
 */
class RunBackupCommand extends Command
{
    protected $signature = 'backup:run
        {--only=full : Type de backup: full, db ou files.}
        {--include-logs : Inclut les logs applicatifs dans une section separee.}';

    protected $description = 'Cree un backup manuel de la base de donnees et/ou des fichiers prives.';

    public function handle(BackupService $backupService): int
    {
        $type = (string) $this->option('only');
        $includeLogs = (bool) $this->option('include-logs');

        $this->info(sprintf(
            'Creation du backup %s%s...',
            $type,
            $includeLogs ? ' avec logs' : ''
        ));

        try {
            $summary = $backupService->run($type, $includeLogs);
        } catch (InvalidArgumentException $exception) {
            // Type de backup non reconnu (ni full, db, ni files).
            $this->error($exception->getMessage());

            return self::FAILURE;
        } catch (Throwable $exception) {
            // Toute erreur système (permissions, espace disque, dump SQL) est remontée et reportée.
            report($exception);
            $this->error('Le backup a echoue: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Backup cree avec succes.');
        $this->line('Fichier : '.$summary['path']);
        $this->line('Taille : '.$this->formatBytes((int) $summary['size']));

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
