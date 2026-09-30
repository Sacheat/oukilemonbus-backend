<?php

namespace App\Casts;

use DateTimeInterface;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Date-heure locale de la commune (Europe/Brussels), stockée sans fuseau.
 *
 * Même convention que les réservations (trip_date + departure_time) : la valeur stockée est
 * l'heure murale locale, comparée telle quelle aux fenêtres d'occupation. Une valeur reçue avec
 * un décalage explicite (« …Z », « …+02:00 ») est d'abord convertie en heure de Bruxelles ;
 * une valeur sans décalage est déjà locale et conservée telle quelle.
 *
 * @implements CastsAttributes<Carbon, mixed>
 */
class LocalDateTime implements CastsAttributes
{
    /** Format d'exposition dans l'API : heure locale sans fuseau, comme estimated_occupation. */
    public const string API_FORMAT = 'Y-m-d\TH:i:s';

    private const string STORAGE_FORMAT = 'Y-m-d H:i:s';

    private const string EXPLICIT_OFFSET = '/\d{2}:\d{2}(?::\d{2}(?:\.\d+)?)?\s*(?:Z|[+-]\d{2}(?::?\d{2})?)$/i';

    public function get(Model $model, string $key, mixed $value, array $attributes): ?Carbon
    {
        return $value === null ? null : Carbon::parse($value);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return self::normalize($value)?->format(self::STORAGE_FORMAT);
    }

    /**
     * Ramène une date reçue (chaîne ou objet) à l'heure murale locale.
     * Les objets DateTimeInterface sont déjà des heures locales dans l'application : leur heure est conservée.
     */
    public static function normalize(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof DateTimeInterface) {
            return Carbon::parse($value->format(self::STORAGE_FORMAT));
        }

        $value = trim((string) $value);

        if (preg_match(self::EXPLICIT_OFFSET, $value) === 1) {
            $local = Carbon::parse($value)->setTimezone(config('reservations.local_timezone', 'Europe/Brussels'));

            return Carbon::parse($local->format(self::STORAGE_FORMAT));
        }

        return Carbon::parse($value);
    }
}
