<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['municipality_id', 'kind', 'label', 'start_date', 'end_date'])]
/**
 * Période du calendrier d'une commune : fermeture (aucune réservation) ou vacances scolaires (sautées par les séries).
 * Les jours fériés légaux ne sont pas stockés : ClosedDaysService les calcule pour chaque année.
 */
class MunicipalityCalendarPeriod extends Model
{
    public const CLOSURE = 'fermeture';

    public const SCHOOL_HOLIDAYS = 'vacances_scolaires';

    public const KINDS = [self::CLOSURE, self::SCHOOL_HOLIDAYS];

    public function municipality(): BelongsTo
    {
        return $this->belongsTo(Municipality::class);
    }

    protected function casts(): array
    {
        return [
            'start_date' => 'date:Y-m-d',
            'end_date' => 'date:Y-m-d',
        ];
    }
}
