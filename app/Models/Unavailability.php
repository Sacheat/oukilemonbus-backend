<?php

namespace App\Models;

use App\Casts\LocalDateTime;
use App\Enums\UnavailabilityReasonType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'reason_type',
    'reason',
    'driver_id',
    'vehicle_id',
    'start_datetime',
    'end_datetime',
    'created_by_user_id',
    'is_active',
])]
/**
 * Indisponibilité d'un chauffeur ou d'un véhicule.
 * `is_active = false` = annulation logique — la ligne est conservée pour l'historique.
 * `start_datetime` / `end_datetime` : heure locale de Bruxelles sans fuseau (voir LocalDateTime).
 * `driver_id` XOR `vehicle_id` : une indisponibilité cible soit un chauffeur soit un véhicule,
 * jamais les deux (validé par UnavailabilityService).
 */
class Unavailability extends Model
{
    use HasFactory;

    /** Aligné sur la valeur par défaut de la colonne, pour que le modèle créé la connaisse sans refresh. */
    protected $attributes = [
        'is_active' => true,
    ];

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function history(): HasMany
    {
        return $this->hasMany(UnavailabilityHistory::class);
    }

    /**
     * Indisponibilités actives qui s'appliquent maintenant : début ≤ maintenant ≤ fin, en heure de Bruxelles sans fuseau
     * (comme les réservations et le moteur de conflits). Utilisé par GET /unavailabilities?current=1 (état du jour des
     * pages Véhicules et Chauffeurs).
     */
    public function scopeCurrentlyActive(Builder $query): void
    {
        $now = CarbonImmutable::now(config('reservations.local_timezone', 'Europe/Brussels'))->format('Y-m-d H:i:s');

        $query->where('is_active', true)
            ->where('start_datetime', '<=', $now)
            ->where('end_datetime', '>=', $now);
    }

    protected function casts(): array
    {
        return [
            'reason_type' => UnavailabilityReasonType::class,
            'start_datetime' => LocalDateTime::class,
            'end_datetime' => LocalDateTime::class,
            'is_active' => 'boolean',
        ];
    }
}
