<?php

namespace App\Services\Reservations;

use App\Enums\OrganizationType;
use App\Enums\ReservationStatus;
use App\Models\Organization;
use Illuminate\Validation\ValidationException;

/**
 * Vérifie que l'organisation n'a pas atteint ses plafonds de réservations.
 *
 * Trois limites cumulatives vérifiées dans l'ordre :
 *   1. Demandes en attente (EN_ATTENTE + EN_CONFLIT, futures) ≤ max_pending_requests (défaut 2).
 *   2. Réservations confirmées futures ≤ max_confirmed_reservations (défaut 2).
 *   3. Quota annuel APPROUVE + CONFIRME pour l'année du trajet
 *      (min entre organization.annual_quota et la config, défaut 5).
 *
 * Les organisations isQuotaExempt() (ex. écoles) ignorent toutes ces vérifications.
 */
class ReservationQuotaService
{
    public function ensureCanCreate(Organization $organization, string $tripDate): void
    {
        if ($this->isExempt($organization)) {
            return;
        }

        $pendingStatuses = [
            ReservationStatus::EN_ATTENTE->value,
            ReservationStatus::EN_CONFLIT->value,
        ];

        $pendingFutureCount = $organization->reservations()
            ->whereIn('status', $pendingStatuses)
            ->whereDate('trip_date', '>=', now()->toDateString())
            ->count();

        if ($pendingFutureCount >= config('reservations.max_pending_requests', 2)) {
            throw ValidationException::withMessages([
                'organization_id' => 'Cette organisation a déjà atteint le maximum de demandes en attente de décision.',
            ]);
        }

        $confirmedFutureCount = $organization->reservations()
            ->where('status', ReservationStatus::CONFIRME->value)
            ->whereDate('trip_date', '>=', now()->toDateString())
            ->count();

        if ($confirmedFutureCount >= config('reservations.max_confirmed_reservations', 2)) {
            throw ValidationException::withMessages([
                'organization_id' => 'Cette organisation a déjà atteint le maximum de réservations confirmées.',
            ]);
        }

        $annualQuota = min(
            $organization->annual_quota ?: config('reservations.annual_quota', 5),
            config('reservations.annual_quota', 5)
        );

        $yearlyCount = $organization->reservations()
            ->whereIn('status', [ReservationStatus::APPROUVE->value, ReservationStatus::CONFIRME->value])
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
}
