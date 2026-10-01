<?php

namespace App\Models;

use App\Enums\ReservationStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'frequency',
    'interval_value',
    'days_of_week',
    'start_date',
    'end_date',
    'occurrence_count',
    'is_active',
])]
/**
 * Série de réservations récurrentes.
 * N'a pas de FK directe vers Organization — l'organisation est déduite via ses réservations enfants.
 * `days_of_week` est stocké comme une chaîne CSV ("1,3,5" = lundi, mercredi, vendredi).
 * `is_active = false` quand toutes les occurrences restantes ont été annulées.
 * `referenceReservation` (première occurrence) porte les infos communes à la série
 * (organisation, lieux, horaires, public) ; le résumé (withSummary / loadSummary) les charge
 * avec les compteurs par statut et la prochaine date active, sans N+1.
 */
class Recurrence extends Model
{
    use HasFactory;

    /** Statuts d'une occurrence encore à venir pour next_trip_date. */
    public const ACTIVE_STATUSES = [
        ReservationStatus::EN_ATTENTE,
        ReservationStatus::APPROUVE,
        ReservationStatus::CONFIRME,
    ];

    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class);
    }

    public function referenceReservation(): HasOne
    {
        return $this->hasOne(Reservation::class)->oldestOfMany('trip_date');
    }

    public function scopeWithSummary(Builder $query): void
    {
        $query->with(self::summaryRelations())
            ->withCount(self::summaryCounts())
            ->withMin(self::nextTripAggregate(), 'trip_date');
    }

    public function loadSummary(): static
    {
        return $this->load(self::summaryRelations())
            ->loadCount(self::summaryCounts())
            ->loadMin(self::nextTripAggregate(), 'trip_date');
    }

    /**
     * Compteurs par statut sous la forme { "en_attente": 3, "approuve": 0, ... }.
     * Tous les statuts sont présents, à 0 si aucune occurrence.
     *
     * @return array<string, int>
     */
    public function statusCounts(): array
    {
        $counts = [];
        foreach (ReservationStatus::cases() as $status) {
            $counts[$status->value] = (int) $this->getAttribute("status_{$status->value}_count");
        }

        return $counts;
    }

    private static function summaryRelations(): array
    {
        return [
            'referenceReservation.organization',
            'referenceReservation.pickupLocation',
            'referenceReservation.destinationLocation',
        ];
    }

    private static function summaryCounts(): array
    {
        $counts = ['reservations'];
        foreach (ReservationStatus::cases() as $status) {
            $counts["reservations as status_{$status->value}_count"] = fn ($q) => $q->where('status', $status->value);
        }

        return $counts;
    }

    private static function nextTripAggregate(): array
    {
        return [
            'reservations as next_trip_date' => fn ($q) => $q
                ->whereDate('trip_date', '>=', Reservation::localToday())
                ->whereIn('status', array_map(fn (ReservationStatus $s) => $s->value, self::ACTIVE_STATUSES)),
        ];
    }

    protected function casts(): array
    {
        return [
            'interval_value' => 'integer',
            'start_date' => 'date',
            'end_date' => 'date',
            'occurrence_count' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
