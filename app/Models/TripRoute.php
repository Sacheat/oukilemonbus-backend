<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tracé réel d'un trajet (lieu de départ → destination), calculé par OpenRouteService à la première ouverture d'une
 * mission et gardé. `signature` : lieux et coordonnées du calcul ; s'ils changent, le tracé est recalculé.
 * Règles : TripRouteService.
 */
class TripRoute extends Model
{
    protected $fillable = [
        'reservation_id',
        'signature',
        'geometry',
        'distance_m',
        'duration_s',
        'steps',
        'provider',
        'computed_at',
    ];

    protected function casts(): array
    {
        return [
            'geometry' => 'array',
            'steps' => 'array',
            'distance_m' => 'integer',
            'duration_s' => 'integer',
            'computed_at' => 'datetime',
        ];
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }
}
