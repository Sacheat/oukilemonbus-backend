<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['user_id', 'licence_number', 'licence_expiry_date', 'is_active'])]
/**
 * Profil chauffeur lié 1:1 à un User.
 * `is_active = false` : chauffeur désactivé — ne peut plus être assigné, profil conservé.
 * `oldAssignmentHistory` / `newAssignmentHistory` : accès à l'historique des affectations
 * avant et après changement (AssignmentType DRIVER_ONLY ou BOTH).
 */
class Driver extends Model
{
    use HasFactory;

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

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
        return $this->hasMany(ReservationAssignmentHistory::class, 'old_driver_id');
    }

    public function newAssignmentHistory(): HasMany
    {
        return $this->hasMany(ReservationAssignmentHistory::class, 'new_driver_id');
    }

    protected function casts(): array
    {
        return [
            'licence_expiry_date' => 'date',
            'is_active' => 'boolean',
        ];
    }
}
