<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['licence_plate', 'capacity', 'is_active'])]
/**
 * Véhicule de transport.
 * `is_active = false` : véhicule hors service — ne peut plus être assigné, historique conservé.
 * `oldAssignmentHistory` / `newAssignmentHistory` : accès aux affectations avant/après changement.
 */
class Vehicle extends Model
{
    use HasFactory;

    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class);
    }

    public function unavailabilities(): HasMany
    {
        return $this->hasMany(Unavailability::class);
    }

    public function oldAssignmentHistory(): HasMany
    {
        return $this->hasMany(ReservationAssignmentHistory::class, 'old_vehicle_id');
    }

    public function newAssignmentHistory(): HasMany
    {
        return $this->hasMany(ReservationAssignmentHistory::class, 'new_vehicle_id');
    }

    protected function casts(): array
    {
        return [
            'capacity' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
