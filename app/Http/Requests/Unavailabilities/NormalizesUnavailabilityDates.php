<?php

namespace App\Http\Requests\Unavailabilities;

use App\Casts\LocalDateTime;
use Throwable;

/**
 * Convertit start_datetime / end_datetime en heure locale de Bruxelles avant validation,
 * pour que « before / after » comparent des valeurs de même nature (voir LocalDateTime).
 */
trait NormalizesUnavailabilityDates
{
    protected function prepareForValidation(): void
    {
        foreach (['start_datetime', 'end_datetime'] as $field) {
            $value = $this->input($field);

            if (! is_string($value) || $value === '') {
                continue;
            }

            try {
                $this->merge([$field => LocalDateTime::normalize($value)->format('Y-m-d H:i:s')]);
            } catch (Throwable) {
                // Valeur illisible : laissée telle quelle pour que la règle « date » la refuse.
            }
        }
    }
}
