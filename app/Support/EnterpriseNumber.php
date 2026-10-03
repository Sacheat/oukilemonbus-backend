<?php

namespace App\Support;

/**
 * Numéro d'entreprise belge (BCE) : 10 chiffres, le premier est 0 ou 1, les deux derniers sont la clé de contrôle
 * (97 − les 8 premiers chiffres modulo 97). Accepte « 0123.456.749 », « BE 0123 456 749 », « 123456749 » (9 chiffres :
 * le zéro de tête est rétabli). La clé ne prouve pas que l'entreprise existe, seulement que le numéro est bien formé.
 */
final class EnterpriseNumber
{
    /** @return string|null les 10 chiffres, ou null si la valeur n'a pas la forme d'un numéro BCE */
    public static function normalize(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', preg_replace('/^\s*BE/i', '', $value));

        if (strlen($digits) === 9) {
            $digits = '0'.$digits;
        }

        return strlen($digits) === 10 ? $digits : null;
    }

    public static function isValid(?string $value): bool
    {
        $number = self::normalize($value);

        if ($number === null || ! in_array($number[0], ['0', '1'], true)) {
            return false;
        }

        return 97 - ((int) substr($number, 0, 8) % 97) === (int) substr($number, 8, 2);
    }
}
