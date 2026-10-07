<?php

namespace App\Models;

use App\Enums\DriverAlertStatus;
use App\Enums\PositionState;
use App\Enums\TripProgressState;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Demande d'aide d'un chauffeur (bouton « Aide ») : position du téléphone ou dernier lieu connu, trajet et véhicule
 * concernés, puis prise en charge et clôture par un agent. Règles : DriverAlertService.
 */
class DriverAlert extends Model
{
    protected $fillable = [
        'driver_id',
        'reservation_id',
        'vehicle_id',
        'status',
        'message',
        'trip_progress',
        'position_state',
        'latitude',
        'longitude',
        'accuracy_m',
        'position_captured_at',
        'position_updates',
        'fallback_label',
        'fallback_latitude',
        'fallback_longitude',
        'created_by_user_id',
        'handled_by_user_id',
        'handled_at',
        'reminded_at',
        'closed_by_user_id',
        'closed_at',
        'closing_note',
    ];

    protected function casts(): array
    {
        return [
            'status' => DriverAlertStatus::class,
            'trip_progress' => TripProgressState::class,
            'position_state' => PositionState::class,
            'latitude' => 'float',
            'longitude' => 'float',
            'accuracy_m' => 'integer',
            'position_captured_at' => 'datetime',
            'position_updates' => 'integer',
            'fallback_latitude' => 'float',
            'fallback_longitude' => 'float',
            'handled_at' => 'datetime',
            'reminded_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function handledByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handled_by_user_id');
    }

    public function closedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by_user_id');
    }

    /** Coordonnées à montrer sur une carte : la position du téléphone, sinon le dernier lieu connu */
    public function mapPoint(): ?array
    {
        if ($this->latitude !== null && $this->longitude !== null) {
            return ['latitude' => $this->latitude, 'longitude' => $this->longitude, 'exact' => true];
        }

        if ($this->fallback_latitude !== null && $this->fallback_longitude !== null) {
            return ['latitude' => $this->fallback_latitude, 'longitude' => $this->fallback_longitude, 'exact' => false];
        }

        return null;
    }
}
