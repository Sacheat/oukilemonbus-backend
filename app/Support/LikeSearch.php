<?php

namespace App\Support;

/**
 * Découpe une recherche libre en mots et prépare les motifs LIKE correspondants.
 * Les jokers % et _ saisis par l'utilisateur sont échappés (recherchés littéralement).
 * Casse et accents : ignorés par la collation utf8mb4_unicode_ci des colonnes.
 */
final class LikeSearch
{
    /** @return list<string> motifs « %mot% », un par mot */
    public static function patterns(?string $search): array
    {
        $words = preg_split('/\s+/', trim((string) $search), -1, PREG_SPLIT_NO_EMPTY);

        return array_map(fn (string $word): string => '%'.addcslashes($word, '%_\\').'%', $words);
    }
}
