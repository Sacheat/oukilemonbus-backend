<?php

namespace App\Console\Commands;

use App\Services\Backups\BackupEncryptor;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Déchiffre une archive `.tar.gz.enc` avec BACKUP_ENCRYPTION_KEY, pour une restauration. Le fichier en clair contient
 * toute la base : le supprimer dès la restauration faite.
 */
class DecryptBackupCommand extends Command
{
    protected $signature = 'backup:decrypt
        {archive : Chemin de l\'archive .tar.gz.enc}
        {--output= : Fichier de sortie (défaut : même chemin sans .enc)}';

    protected $description = 'Déchiffre une archive de backup chiffrée';

    public function handle(BackupEncryptor $encryptor): int
    {
        $archive = (string) $this->argument('archive');
        $output = (string) ($this->option('output') ?: preg_replace('/'.preg_quote(BackupEncryptor::EXTENSION, '/').'$/', '', $archive));

        if ($output === $archive) {
            $this->error('Précisez --output : l\'archive ne se termine pas par '.BackupEncryptor::EXTENSION.'.');

            return self::FAILURE;
        }

        if (file_exists($output)) {
            $this->error("{$output} existe déjà.");

            return self::FAILURE;
        }

        try {
            $encryptor->decrypt($archive, $output, (string) config('backups.encryption.key'));
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Archive déchiffrée : {$output}");
        $this->warn('Supprimez ce fichier en clair une fois la restauration terminée.');

        return self::SUCCESS;
    }
}
