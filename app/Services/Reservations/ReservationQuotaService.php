<?php

namespace App\Services\Reservations;

use App\Enums\OrganizationType;
use App\Enums\ReservationStatus;
use App\Models\Organization;
use App\Models\Reservation;
use Illuminate\Validation\ValidationException;

/**
 * Vérifie que l'organisation n'a pas atteint ses plafonds de réservations.
 *
 * Trois limites cumulatives vérifiées dans l'ordre :
 *   1. Demandes en attente (EN_ATTENTE + EN_CONFLIT, futures) ≤ max_pending_requests (défaut 2).
 *   2. Réservations confirmées futures ≤ max_confirmed_reservations (défaut 2).
 *   3. Quota annuel APPROUVE + CONFIRME pour l'année du trajet : organization.annual_quota, réglable par un agent
 *      dans les deux sens (0 = aucune réservation) ; config('reservations.annual_quota') n'est que la valeur par défaut
 *      d'une nouvelle organisation. Le compteur organizations.reservations_used_this_year n'est plus utilisé : on
 *      compte, donc la remise à zéro au 1er janvier se fait d'elle-même.
 *
 * `summary()` donne le même calcul pour l'affichage (jauge du quota), afin qu'il n'y ait qu'une règle.
 *
 * Les organisations isQuotaExempt() (ex. écoles) ignorent toutes ces vérifications.
 */
class ReservationQuotaService
{
    /** Réservations qui consomment le quota annuel */
    public const COUNTED_STATUSES = [ReservationStatus::APPROUVE->value, ReservationStatus::CONFIRME->value];

    /**
     * @param  int|null  $ignoreReservationId  réservation en cours de modification : exclue des compteurs,
     *                                          sinon elle se compterait elle-même (ex. 2 demandes en attente sur 2).
     */
    public function ensureCanCreate(Organization $organization, string $tripDate, ?int $ignoreReservationId = null): void
    {
        if ($this->isExempt($organization)) {
            return;
        }

        $pendingStatuses = [
            ReservationStatus::EN_ATTENTE->value,
            ReservationStatus::EN_CONFLIT->value,
        ];

        $pendingFutureCount = $organization->reservations()
            ->when($ignoreReservationId !== null, fn ($query) => $query->whereKeyNot($ignoreReservationId))
            ->whereIn('status', $pendingStatuses)
            ->whereDate('trip_date', '>=', Reservation::localToday())
            ->count();

        if ($pendingFutureCount >= config('reservations.max_pending_requests', 2)) {
            throw ValidationException::withMessages([
                'organization_id' => 'Cette organisation a déjà atteint le maximum de demandes en attente de décision.',
            ]);
        }

        $confirmedFutureCount = $organization->reservations()
            ->when($ignoreReservationId !== null, fn ($query) => $query->whereKeyNot($ignoreReservationId))
            ->where('status', ReservationStatus::CONFIRME->value)
            ->whereDate('trip_date', '>=', Reservation::localToday())
            ->count();

        if ($confirmedFutureCount >= config('reservations.max_confirmed_reservations', 2)) {
            throw ValidationException::withMessages([
                'organization_id' => 'Cette organisation a déjà atteint le maximum de réservations confirmées.',
            ]);
        }

        $annualQuota = $this->limit($organization);

        $yearlyCount = $organization->reservations()
            ->when($ignoreReservationId !== null, fn ($query) => $query->whereKeyNot($ignoreReservationId))
            ->whereIn('status', self::COUNTED_STATUSES)
            ->whereYear('trip_date', substr($tripDate, 0, 4))
            ->count();

        if ($yearlyCount >= $annualQuota) {
            throw ValidationException::withMessages([
                'organization_id' => 'Cette organisation a déjà atteint son quota annuel de réservations.',
            ]);
        }
    }

    public function isExempt(Organization $organization): bool
    {
        return $organization->type?->isQuotaExempt() ?? false;
    }

    /** Quota annuel d'une organisation : celui qu'un agent a réglé (0 = aucune réservation possible). */
    public function limit(Organization $organization): int
    {
        return max(0, (int) $organization->annual_quota);
    }

    /**
     * Quota de l'année en cours (Bruxelles), pour l'affichage : même règle que le blocage.
     * `$usedCount` : nombre déjà compté par la requête de la liste (withCount `quota_used_count`), sinon compté ici.
     *
     * @return array{exempt: bool, used: int, limit: int, remaining: ?int, year: int}
     */
    public function summary(Organization $organization, ?int $usedCount = null): array
    {
        $year = (int) substr(Reservation::localToday(), 0, 4);
        $exempt = $this->isExempt($organization);
        $used = $usedCount ?? $organization->reservations()
            ->whereIn('status', self::COUNTED_STATUSES)
            ->whereYear('trip_date', $year)
            ->count();
        $limit = $this->limit($organization);

        return [
            'exempt' => $exempt,
            'used' => $used,
            'limit' => $limit,
            'remaining' => $exempt ? null : max(0, $limit - $used),
            'year' => $year,
        ];
    }
}
