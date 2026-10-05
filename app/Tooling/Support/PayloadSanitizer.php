<?php

declare(strict_types=1);

namespace App\Tooling\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * Expurge un tableau avant de l'exposer ou de le stocker : toute clé qui contient un fragment de
 * `tooling.inspector.redacted_keys` (password, token…) est masquée, les fichiers sont résumés et
 * les chaînes tronquées. Utilisé par le journal de l'Inspector et par le détail des jobs en échec.
 */
final class PayloadSanitizer
{
    public const REDACTED = '[masqué]';

    private const MAX_STRING_LENGTH = 1000;

    public function sanitize(array $data): array
    {
        $result = [];

        foreach ($data as $key => $value) {
            if (is_string($key) && $this->isSensitiveKey($key)) {
                $result[$key] = self::REDACTED;
            } elseif ($value instanceof UploadedFile) {
                $result[$key] = sprintf('[fichier : %s, %d o]', $value->getClientOriginalName(), $value->getSize());
            } elseif (is_array($value)) {
                $result[$key] = $this->sanitize($value);
            } elseif (is_string($value)) {
                $result[$key] = Str::limit($value, self::MAX_STRING_LENGTH);
            } else {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    private function isSensitiveKey(string $key): bool
    {
        return Str::contains(
            Str::lower($key),
            array_map('strtolower', (array) config('tooling.inspector.redacted_keys', []))
        );
    }
}
