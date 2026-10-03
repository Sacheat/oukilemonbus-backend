<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Comparaison de noms d'organisation pour repérer les doublons : casse, accents, ponctuation et mots génériques
 * (« ASBL », « école », « les », « du »…) sont ignorés, donc « ASBL Les Amis du Parc » et « Amis du parc » sont le même nom.
 */
final class OrganizationName
{
    private const array GENERIC_WORDS = [
        'asbl', 'vzw', 'aisbl', 'ecole', 'ecoles', 'communale', 'communal', 'personnel', 'cpas', 'club', 'association',
        'asso', 'de', 'du', 'des', 'la', 'le', 'les', 'l', 'd', 'et', 'au', 'aux', 'en', 'a',
    ];

    /** @return list<string> mots significatifs (sans mots génériques) ; tous les mots si le nom n'est fait que de mots génériques */
    public static function words(string $name): array
    {
        $all = preg_split('/[^a-z0-9]+/', Str::lower(Str::ascii($name)), -1, PREG_SPLIT_NO_EMPTY);
        $significant = array_values(array_diff($all, self::GENERIC_WORDS));

        return $significant !== [] ? $significant : $all;
    }

    public static function key(string $name): string
    {
        return implode('', self::words($name));
    }

    /** Même nom, ou une seule lettre d'écart (coquille) pour un nom d'au moins 6 lettres. */
    public static function areNearlyIdentical(string $a, string $b): bool
    {
        $keyA = self::key($a);
        $keyB = self::key($b);

        if ($keyA === '' || $keyB === '') {
            return false;
        }

        if ($keyA === $keyB) {
            return true;
        }

        return min(strlen($keyA), strlen($keyB)) >= 6 && levenshtein($keyA, $keyB) <= 1;
    }
}
