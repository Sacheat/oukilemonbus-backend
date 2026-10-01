<?php

namespace App\Services\Dashboard;

use App\Enums\OrganizationType;
use App\Enums\RequestStatus;
use App\Enums\ReservationStatus;
use App\Models\Driver;
use App\Models\OrgRegistrationRequest;
use App\Models\Reservation;
use App\Models\Unavailability;
use App\Models\UserOrgRequest;
use App\Models\Vehicle;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Indicateurs du tableau de bord de gestion (GET /api/dashboard, admin et agents).
 *
 * Un indicateur = une méthode. Les règles viennent des sources existantes :
 * statuts actifs et approuvables (ReservationStatus), types soumis au Collège (OrganizationType),
 * fenêtre de confirmation des passagers (config reservations). « Aujourd'hui » est le jour
 * en heure de Bruxelles, comme les trajets et les indisponibilités (heures locales sans fuseau).
 */
class DashboardService
{
    public const int WEEK_DAYS = 7;

    public const int LICENCE_ALERT_DAYS = 30;

    public function summary(): array
    {
        $today = $this->today();

        return [
            'to_process' => [
                'pending' => $this->countByStatus(ReservationStatus::EN_ATTENTE),
                'in_conflict' => $this->countByStatus(ReservationStatus::EN_CONFLIT),
                'awaiting_assignment' => $this->awaitingAssignmentCount(),
                'passengers_to_confirm' => $this->passengersToConfirmCount($today),
                'council_pending' => $this->councilPendingCount(),
                'join_requests_pending' => UserOrgRequest::query()->where('status', RequestStatus::EN_ATTENTE->value)->count(),
                'registration_requests_pending' => OrgRegistrationRequest::query()->where('status', RequestStatus::EN_ATTENTE->value)->count(),
            ],
            'today' => $this->todayTrips($today),
            'week' => $this->week($today),
            'fleet_alerts' => [
                'vehicles_unavailable_today' => $this->unavailableTodayCount('vehicle_id', $today),
                'drivers_unavailable_today' => $this->unavailableTodayCount('driver_id', $today),
                'licences_expiring_30_days' => $this->expiringLicences($today),
                'inactive_vehicles' => Vehicle::query()->where('is_active', false)->count(),
            ],
        ];
    }

    private function today(): CarbonImmutable
    {
        return CarbonImmutable::parse(Reservation::localToday());
    }

    private function activeReservations(): Builder
    {
        return Reservation::query()->whereIn('status', ReservationStatus::activeValues());
    }

    private function countByStatus(ReservationStatus $status): int
    {
        return Reservation::query()->where('status', $status->value)->count();
    }

    /** Approuvées sans chauffeur ou sans véhicule : l'affectation reste à faire. */
    private function awaitingAssignmentCount(): int
    {
        return Reservation::query()
            ->where('status', ReservationStatus::APPROUVE->value)
            ->where(fn (Builder $q) => $q->whereNull('driver_id')->orWhereNull('vehicle_id'))
            ->count();
    }

    /** Trajets actifs d'aujourd'hui à J+N−1 dont les passagers ne sont pas encore confirmés. */
    private function passengersToConfirmCount(CarbonImmutable $today): int
    {
        $windowDays = (int) config('reservations.passenger_confirmation_window_days', 14);

        return $this->activeReservations()
            ->whereNull('passengers_confirmed_at')
            ->whereDate('trip_date', '>=', $today->toDateString())
            ->whereDate('trip_date', '<=', $today->addDays($windowDays - 1)->toDateString())
            ->count();
    }

    /**
     * Réservations encore approuvables d'un type soumis au Collège, sans décision
     * ou avec une décision encore en attente : l'approbation est bloquée par le Collège.
     * Même règle que GET /reservations?council=a_decider (scope Reservation::awaitingCouncilDecision).
     */
    private function councilPendingCount(): int
    {
        return Reservation::query()->awaitingCouncilDecision()->count();
    }

    private function todayTrips(CarbonImmutable $today): array
    {
        $trips = $this->activeReservations()
            ->whereDate('trip_date', $today->toDateString())
            ->with(['organization:id,name', 'driver.user:id,first_name,last_name', 'vehicle:id,licence_plate'])
            ->orderBy('departure_time')
            ->orderBy('id')
            ->get();

        return [
            'date' => $today->toDateString(),
            'trips_count' => $trips->count(),
            'trips' => $trips->map(fn (Reservation $reservation): array => [
                'id' => $reservation->id,
                'trip_date' => $reservation->trip_date->toDateString(),
                'departure_time' => $reservation->departure_time->format('H:i:s'),
                'return_time' => $reservation->return_time->format('H:i:s'),
                'status' => $reservation->status->value,
                'status_label' => $reservation->status->label(),
                'trip_purpose' => $reservation->trip_purpose,
                'organization' => $reservation->organization
                    ? ['id' => $reservation->organization->id, 'name' => $reservation->organization->name]
                    : null,
                'driver' => $reservation->driver
                    ? ['id' => $reservation->driver->id, 'full_name' => $this->fullName($reservation->driver)]
                    : null,
                'vehicle' => $reservation->vehicle
                    ? ['id' => $reservation->vehicle->id, 'licence_plate' => $reservation->vehicle->licence_plate]
                    : null,
            ])->values()->all(),
        ];
    }

    /** Trajets actifs par jour, d'aujourd'hui à J+6, jours sans trajet inclus (count = 0). */
    private function week(CarbonImmutable $today): array
    {
        $end = $today->addDays(self::WEEK_DAYS - 1);

        $counts = $this->activeReservations()
            ->whereDate('trip_date', '>=', $today->toDateString())
            ->whereDate('trip_date', '<=', $end->toDateString())
            ->get(['trip_date'])
            ->countBy(fn (Reservation $reservation): string => $reservation->trip_date->toDateString());

        $days = [];
        for ($date = $today; $date->lte($end); $date = $date->addDay()) {
            $days[] = ['date' => $date->toDateString(), 'count' => $counts->get($date->toDateString(), 0)];
        }

        return [
            'start_date' => $today->toDateString(),
            'end_date' => $end->toDateString(),
            'trips_by_day' => $days,
        ];
    }

    /** Chauffeurs ou véhicules distincts ayant une indisponibilité active qui touche la journée. */
    private function unavailableTodayCount(string $subjectColumn, CarbonImmutable $today): int
    {
        return Unavailability::query()
            ->where('is_active', true)
            ->whereNotNull($subjectColumn)
            ->where('start_datetime', '<', $today->addDay()->format('Y-m-d H:i:s'))
            ->where('end_datetime', '>', $today->format('Y-m-d H:i:s'))
            ->distinct()
            ->count($subjectColumn);
    }

    /** Chauffeurs actifs dont le permis est expiré ou expire dans les 30 jours, le plus urgent d'abord. */
    private function expiringLicences(CarbonImmutable $today): array
    {
        return Driver::query()
            ->where('is_active', true)
            ->whereDate('licence_expiry_date', '<=', $today->addDays(self::LICENCE_ALERT_DAYS)->toDateString())
            ->with('user:id,first_name,last_name')
            ->orderBy('licence_expiry_date')
            ->orderBy('id')
            ->get()
            ->map(fn (Driver $driver): array => [
                'driver_id' => $driver->id,
                'full_name' => $this->fullName($driver),
                'licence_expiry_date' => $driver->licence_expiry_date->toDateString(),
            ])
            ->all();
    }

    private function fullName(Driver $driver): ?string
    {
        return $driver->user ? trim($driver->user->first_name.' '.$driver->user->last_name) : null;
    }
}
