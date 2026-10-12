<?php

namespace App\Services\Backups;

use RuntimeException;

/**
 * Chiffrement des archives de backup (RGPD : la base et les justificatifs ne restent pas en clair sur le disque).
 *
 * libsodium secretstream (XChaCha20-Poly1305), fourni avec PHP : le fichier est lu et écrit par blocs de 64 Ko,
 * jamais chargé en entier. Chaque bloc est authentifié et le dernier porte le tag FINAL : une archive modifiée,
 * tronquée ou déchiffrée avec une autre clé est refusée.
 *
 * Format : `BCBK1` (5 octets) + en-tête secretstream (24 octets) + blocs chiffrés.
 * Clé : BACKUP_ENCRYPTION_KEY = `base64:` + 32 octets aléatoires (php artisan backup:generate-key).
 * Sans la clé, une archive est irrécupérable : la garder hors de la VM (gestionnaire de mots de passe de la commune).
 */
class BackupEncryptor
{
    public const string EXTENSION = '.enc';

    private const string MAGIC = 'BCBK1';

    private const int CHUNK_SIZE = 65536;

    public static function generateKey(): string
    {
        return 'base64:'.base64_encode(sodium_crypto_secretstream_xchacha20poly1305_keygen());
    }

    public function encrypt(string $sourcePath, string $targetPath, string $key): void
    {
        [$state, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push($this->decodeKey($key));
        [$in, $out] = $this->open($sourcePath, $targetPath);

        try {
            fwrite($out, self::MAGIC.$header);

            $chunk = fread($in, self::CHUNK_SIZE);
            do {
                $next = feof($in) ? '' : fread($in, self::CHUNK_SIZE);
                $tag = ($next === '' || $next === false)
                    ? SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL
                    : SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE;

                if (fwrite($out, sodium_crypto_secretstream_xchacha20poly1305_push($state, (string) $chunk, '', $tag)) === false) {
                    throw new RuntimeException("Écriture impossible dans {$targetPath}.");
                }

                $chunk = $next;
            } while ($tag !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL);
        } finally {
            fclose($in);
            fclose($out);
        }
    }

    /** @throws RuntimeException fichier qui n'est pas une archive chiffrée, mauvaise clé, archive modifiée ou tronquée */
    public function decrypt(string $sourcePath, string $targetPath, string $key): void
    {
        [$in, $out] = $this->open($sourcePath, $targetPath);
        $complete = false;

        try {
            $prefix = (string) fread($in, strlen(self::MAGIC) + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES);

            if (! str_starts_with($prefix, self::MAGIC) || strlen($prefix) !== strlen(self::MAGIC) + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES) {
                throw new RuntimeException("{$sourcePath} n'est pas une archive de backup chiffrée.");
            }

            $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull(substr($prefix, strlen(self::MAGIC)), $this->decodeKey($key));
            $encryptedChunkSize = self::CHUNK_SIZE + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES;

            while (! $complete && ($encrypted = fread($in, $encryptedChunkSize)) !== false && $encrypted !== '') {
                $result = sodium_crypto_secretstream_xchacha20poly1305_pull($state, $encrypted);

                if ($result === false) {
                    throw new RuntimeException('Déchiffrement impossible : mauvaise clé ou archive modifiée.');
                }

                [$plain, $tag] = $result;
                fwrite($out, $plain);
                $complete = $tag === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL;
            }

            if (! $complete || fread($in, 1) !== '') {
                throw new RuntimeException('Archive tronquée ou modifiée : la fin chiffrée ne correspond pas.');
            }
        } catch (RuntimeException $exception) {
            fclose($out);
            $out = null;
            @unlink($targetPath);

            throw $exception;
        } finally {
            fclose($in);
            if ($out !== null) {
                fclose($out);
            }
        }
    }

    public function decodeKey(?string $key): string
    {
        $raw = is_string($key) && str_starts_with($key, 'base64:') ? base64_decode(substr($key, 7), true) : false;

        if ($raw === false || strlen($raw) !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES) {
            throw new RuntimeException('BACKUP_ENCRYPTION_KEY invalide : générez-la avec php artisan backup:generate-key.');
        }

        return $raw;
    }

    /** @return array{0: resource, 1: resource} */
    private function open(string $sourcePath, string $targetPath): array
    {
        $in = @fopen($sourcePath, 'rb');
        if ($in === false) {
            throw new RuntimeException("Lecture impossible de {$sourcePath}.");
        }

        $out = @fopen($targetPath, 'wb');
        if ($out === false) {
            fclose($in);
            throw new RuntimeException("Écriture impossible dans {$targetPath}.");
        }

        return [$in, $out];
    }
}
