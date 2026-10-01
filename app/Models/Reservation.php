<?php

namespace App\Models;

use App\Enums\ApprovalType;
use App\Enums\CouncilDecisionStatus;
use App\Enums\OrganizationType;
use App\Enums\ReservationActivityType;
use App\Enums\ReservationStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable([
    'requested_by_user_id',
    'organization_id',
    'pickup_location_id',
    'destination_location_id',
    'driver_id',
    'vehicle_id',
    'recurrence_id',
    'trip_date',
    'departure_time',
    'return_time',
    'nb_children',
    'nb_adults',
    'trip_purpose',
    'activity_type',
    'distance_km',
    'estimated_duration_min',
    'status',
    'approval_type',
    'validated_by_user_id',
    'validated_at',
    'cancelled_by_user_id',
    'cancelled_at',
    'rejection_reason',
    'cancellation_reason',
    'passengers_confirmed_at',
])]
/**
 * Réservation de transport.
 * `scopeVisibleTo` est le filtre de sécurité central : admin/agent voient tout,
 * les autres voient uniquement leurs propres demandes, celles de leurs organisations, ou (chauffeur) celles qui leur sont assignées.
 * `passengers_confirmed_at` : timestamp nullable positionné par confirmPassengers() — null = non confirmé.
 * `routeSheetDocuments` : BelongsToMany via pivot `document_reservation` avec colonne `position` pour l'ordre.
 */
class Reservation extends Model
{
    use HasFactory;

    public function requestedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function pickupLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'pickup_location_id');
    }

    public function destinationLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'destination_location_id');
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function recurrence(): BelongsTo
    {
        return $this->belongsTo(Recurrence::class);
    }

    public function validatedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'validated_by_user_id');
    }

    public function cancelledByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by_user_id');
    }

    public function councilDecision(): HasOne
    {
        return $this->hasOne(CouncilDecision::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    public function statusHistory(): HasMany
    {
        return $this->hasMany(ReservationStatusHistory::class);
    }

    public function assignmentHistory(): HasMany
    {
        return $this->hasMany(ReservationAssignmentHistory::class);
    }

    public function notificationDeliveries(): HasMany
    {
        return $this->hasMany(NotificationDelivery::class);
    }

    public function conflicts(): HasMany
    {
        return $this->hasMany(ReservationConflict::class);
    }

    public function routeSheetDocuments(): BelongsToMany
    {
        return $this->belongsToMany(Document::class, 'document_reservation')
            ->withPivot('position')
            ->withTimestamps();
    }

    /** « Aujourd'hui » des règles métier : le jour à Bruxelles (config reservations.local_timezone), Y-m-d. */
    public static function localToday(): string
    {
        return CarbonImmutable::now(config('reservations.local_timezone', 'Europe/Brussels'))->toDateString();
    }

    /**
     * Départ du trajet comme instant réel : trip_date + departure_time sont des heures murales de Bruxelles,
     * à comparer à now() (UTC) pour les délais en heures (annulation 24h avant le départ…).
     */
    public function departureAt(): ?CarbonImmutable
    {
        if ($this->trip_date === null || $this->departure_time === null) {
            return null;
        }

        return CarbonImmutable::parse(
            $this->trip_date->toDateString().' '.$this->departure_time->format('H:i:s'),
            config('reservations.local_timezone', 'Europe/Brussels')
        );
    }

    /** Le jour du trajet est passé : strictement avant aujourd'hui à Bruxelles (le jour même n'est pas passé). */
    public function hasTripDatePassed(): bool
    {
        return $this->trip_date->toDateString() < self::localToday();
    }

    /**
     * Réservations qui attendent le Collège : encore approuvables, trajet pas encore passé, d'un type d'organisation
     * soumis au Collège (CPAS, ASBL, externe), sans décision ou avec une décision encore « en attente ».
     * Règle unique, utilisée par GET /reservations?council=a_decider et par le compteur du tableau de bord.
     */
    public function scopeAwaitingCouncilDecision(Builder $query): void
    {
        $query->whereIn('status', ReservationStatus::approvableValues())
            ->whereDate('trip_date', '>=', self::localToday())
            ->whereHas('organization', fn (Builder $q) => $q->whereIn('type', OrganizationType::councilDecisionRequiredValues()))
            ->where(fn (Builder $q) => $q
                ->whereDoesntHave('councilDecision')
                ->orWhereHas('councilDecision', fn (Builder $d) => $d->where('decision_status', CouncilDecisionStatus::EN_ATTENTE->value)));
    }

    public function scopeVisibleTo(Builder $query, User $user): void
    {
        if ($user->hasAnyRole(['admin', 'agent_communal'])) {
            return;
        }

        $query->where(function (Builder $query) use ($user): void {
            $query->where('requested_by_user_id', $user->getKey())
                ->orWhereHas('organization.users', fn (Builder $q) => $q->whereKey($user->getKey()))
                ->when(
                    $user->hasRole('chauffeur') && $user->driver !== null,
                    fn (Builder $q) => $q->orWhere('driver_id', $user->driver->getKey())
                );
        });
    }

    protected function casts(): array
    {
        return [
            'trip_date' => 'date',
            'departure_time' => 'datetime:H:i:s',
            'return_time' => 'datetime:H:i:s',
            'nb_children' => 'integer',
            'nb_adults' => 'integer',
            'activity_type' => ReservationActivityType::class,
            'distance_km' => 'decimal:2',
            'estimated_duration_min' => 'integer',
            'status' => ReservationStatus::class,
            'approval_type' => ApprovalType::class,
            'validated_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'passengers_confirmed_at' => 'datetime',
        ];
    }
}
