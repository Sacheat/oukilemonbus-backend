<?php

namespace App\Models;

use App\Enums\PositionState;
use App\Enums\TripEventSource;
use App\Enums\TripEventType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Une étape d'un trajet (départ aller, arrivée à destination, départ retour, arrivée au lieu de départ), avec son
 * instant, sa source (chauffeur, agent, plus tard GPS) et la position relevée. Une par étape et par réservation.
 * Règles : TripProgressService.
 */
class TripEvent extends Model
{
    protected $fillable = [
        'reservation_id',
        'driver_id',
        'type',
        'occurred_at',
        'source',
        'position_state',
        'latitude',
        'longitude',
        'accuracy_m',
        'position_captured_at',
        'recorded_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'type' => TripEventType::class,
            'source' => TripEventSource::class,
            'position_state' => PositionState::class,
            'occurred_at' => 'datetime',
            'position_captured_at' => 'datetime',
            'latitude' => 'float',
            'longitude' => 'float',
            'accuracy_m' => 'integer',
        ];
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    public function recordedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }
}
