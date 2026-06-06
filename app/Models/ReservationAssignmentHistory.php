<?php

namespace App\Models;

use App\Enums\AssignmentType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'reservation_id',
    'assignment_type',
    'old_driver_id',
    'new_driver_id',
    'old_vehicle_id',
    'new_vehicle_id',
    'changed_by_user_id',
    'reason',
])]
/**
 * Historique immuable des changements d'affectation chauffeur/véhicule d'une réservation.
 * `UPDATED_AT = null` : ajout seul. `assignment_type` indique ce qui a changé (DRIVER_ONLY, VEHICLE_ONLY, BOTH).
 * `old_*` / `new_*` : snapshot avant/après permettant de retracer toutes les réaffectations.
 */
class ReservationAssignmentHistory extends Model
{
    use HasFactory;

    protected $table = 'reservation_assignment_history';

    const UPDATED_AT = null;

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function oldDriver(): BelongsTo
    {
        return $this->belongsTo(Driver::class, 'old_driver_id');
    }

    public function newDriver(): BelongsTo
    {
        return $this->belongsTo(Driver::class, 'new_driver_id');
    }

    public function oldVehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class, 'old_vehicle_id');
    }

    public function newVehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class, 'new_vehicle_id');
    }

    public function changedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by_user_id');
    }

    protected function casts(): array
    {
        return [
            'assignment_type' => AssignmentType::class,
            'created_at' => 'datetime',
        ];
    }
}
