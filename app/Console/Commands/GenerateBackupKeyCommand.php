<?php

namespace App\Console\Commands;

use App\Services\Backups\BackupEncryptor;
use Illuminate\Console\Command;

/**
 * Affiche une nouvelle clé pour BACKUP_ENCRYPTION_KEY. Rien n'est écrit : la clé se copie dans le .env et dans un
 * coffre hors de la VM. Changer de clé rend les anciennes archives illisibles avec la nouvelle.
 */
class GenerateBackupKeyCommand extends Command
{
    protected $signature = 'backup:generate-key';

    protected $description = 'Génère une clé de chiffrement des backups (à copier dans BACKUP_ENCRYPTION_KEY)';

    public function handle(): int
    {
        $this->line(BackupEncryptor::generateKey());
        $this->warn('Gardez cette clé hors de la VM : sans elle, les backups chiffrés sont irrécupérables.');

        return self::SUCCESS;
    }
}
