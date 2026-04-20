<?php

namespace App\Services\Reservations;

use App\Enums\OrganizationType;
use App\Enums\ReservationStatus;
use App\Models\Organization;
use Illuminate\Validation\ValidationException;

class ReservationQuotaService
{
    public function ensureCanCreate(Organization $organization, string $tripDate): void
    {
        if ($this->isExempt($organization)) {
            return;
        }

        $activeStatuses = [
            ReservationStatus::EN_ATTENTE->value,
            ReservationStatus::EN_CONFLIT->value,
            ReservationStatus::APPROUVE->value,
            ReservationStatus::CONFIRME->value,
        ];

        $activeFutureCount = $organization->reservations()
            ->whereIn('status', $activeStatuses)
            ->whereDate('trip_date', '>=', now()->toDateString())
            ->count();

        if ($activeFutureCount >= config('reservations.max_active_requests', 2)) {
            throw ValidationException::withMessages([
                'organization_id' => 'Cette organisation a deja atteint le maximum de demandes actives.',
            ]);
        }

        $confirmedFutureCount = $organization->reservations()
            ->where('status', ReservationStatus::CONFIRME->value)
            ->whereDate('trip_date', '>=', now()->toDateString())
            ->count();

        if ($confirmedFutureCount >= config('reservations.max_confirmed_reservations', 2)) {
            throw ValidationException::withMessages([
                'organization_id' => 'Cette organisation a deja atteint le maximum de reservations confirmees.',
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
                'organization_id' => 'Cette organisation a deja atteint son quota annuel de reservations.',
            ]);
        }
    }

    public function isExempt(Organization $organization): bool
    {
        return $organization->type === OrganizationType::ECOLE_COMMUNALE;
    }
}
