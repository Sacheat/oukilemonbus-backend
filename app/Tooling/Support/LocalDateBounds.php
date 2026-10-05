<?php

declare(strict_types=1);

namespace App\Tooling\Support;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;

/**
 * Filtres de date des outils d'administration. Une date seule (AAAA-MM-JJ) désigne une journée en
 * heure locale (`tooling.inspector.local_timezone`, Europe/Brussels) : `from` commence à minuit ce jour-là,
 * `to` inclut toute la journée, `before` s'arrête à minuit. Les colonnes sont en UTC (app.timezone) :
 * la borne est convertie avant la comparaison. Un horodatage complet est comparé tel quel.
 */
final class LocalDateBounds
{
    /** Colonne >= début du jour local (ou horodatage tel quel). */
    public static function applyFrom(EloquentBuilder|QueryBuilder $query, string $column, string $value): void
    {
        $query->where($column, '>=', self::isDateOnly($value) ? self::startOfLocalDay($value) : $value);
    }

    /** Colonne dans le jour local indiqué ou avant (ou <= horodatage tel quel). */
    public static function applyTo(EloquentBuilder|QueryBuilder $query, string $column, string $value): void
    {
        self::isDateOnly($value)
            ? $query->where($column, '<', self::startOfLocalDay($value, addDays: 1))
            : $query->where($column, '<=', $value);
    }

    /** Colonne strictement avant le début du jour local (ou avant l'horodatage tel quel). */
    public static function applyBefore(EloquentBuilder|QueryBuilder $query, string $column, string $value): void
    {
        $query->where($column, '<', self::isDateOnly($value) ? self::startOfLocalDay($value) : $value);
    }

    private static function isDateOnly(string $value): bool
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1;
    }

    private static function startOfLocalDay(string $date, int $addDays = 0): string
    {
        return Carbon::createFromFormat('Y-m-d', $date, (string) config('tooling.inspector.local_timezone', 'Europe/Brussels'))
            ->startOfDay()
            ->addDays($addDays)
            ->setTimezone((string) config('app.timezone', 'UTC'))
            ->toDateTimeString();
    }
}
