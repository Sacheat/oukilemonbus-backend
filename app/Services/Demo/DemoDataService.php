<?php

declare(strict_types=1);

namespace App\Services\Demo;

use App\Models\Document;
use App\Models\OrgRegistrationRequest;
use App\Models\Organization;
use App\Models\Reservation;
use App\Models\Unavailability;
use App\Models\User;
use App\Models\UserOrgRequest;
use App\Services\Documents\DocumentAccessService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

final class DemoDataService
{
    public function __construct(
        private readonly DocumentAccessService $documentAccessService,
    ) {
    }

    public function reservations(User $actor, array $filters = [], int $perPage = 12): LengthAwarePaginator
    {
        return $this->visibleReservationsQuery($actor)
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($filters['organization_id'] ?? null, fn (Builder $query, string $organizationId) => $query->where('organization_id', $organizationId))
            ->when($filters['date_from'] ?? null, fn (Builder $query, string $date) => $query->whereDate('trip_date', '>=', $date))
            ->when($filters['date_to'] ?? null, fn (Builder $query, string $date) => $query->whereDate('trip_date', '<=', $date))
            ->orderByDesc('trip_date')
            ->orderBy('departure_time')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function organizations(User $actor, int $perPage = 12): LengthAwarePaginator
    {
        return Organization::query()
            ->with(['location', 'users'])
            ->when(
                ! $actor->hasAnyRole(['admin', 'agent_communal']),
                fn (Builder $query) => $query->whereHas('users', fn (Builder $query) => $query->whereKey($actor->getKey()))
            )
            ->orderBy('name')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function orgRegistrationRequests(User $actor, int $perPage = 12): LengthAwarePaginator
    {
        return OrgRegistrationRequest::query()
            ->with(['requestedByUser', 'reviewedByUser', 'location'])
            ->when(
                ! $actor->hasAnyRole(['admin', 'agent_communal']),
                fn (Builder $query) => $query->where('request_by_user_id', $actor->getKey())
            )
            ->orderByDesc('confidence_note')
            ->latest()
            ->paginate($perPage)
            ->withQueryString();
    }

    public function userOrgRequests(User $actor, int $perPage = 12): LengthAwarePaginator
    {
        return UserOrgRequest::query()
            ->with(['organization', 'requestedByUser', 'reviewedByUser'])
            ->when(
                ! $actor->hasAnyRole(['admin', 'agent_communal']),
                fn (Builder $query) => $query->where(function (Builder $query) use ($actor): void {
                    $query->where('requested_by_user_id', $actor->getKey())
                        ->when(
                            $actor->hasRole('responsable_organisation'),
                            fn (Builder $query) => $query->orWhereHas(
                                'organization.users',
                                fn (Builder $query) => $query->whereKey($actor->getKey())
                            )
                        );
                })
            )
            ->orderByDesc('confidence_note')
            ->latest()
            ->paginate($perPage)
            ->withQueryString();
    }

    public function documents(User $actor, int $perPage = 12): LengthAwarePaginator
    {
        return $this->documentAccessService
            ->visibleQueryFor($actor)
            ->with(['reservation.organization', 'user'])
            ->latest()
            ->paginate($perPage)
            ->withQueryString();
    }

    public function unavailabilities(User $actor, int $perPage = 12): LengthAwarePaginator
    {
        return Unavailability::query()
            ->with(['driver.user', 'vehicle', 'createdByUser'])
            ->when(
                ! $actor->hasAnyRole(['admin', 'agent_communal']),
                fn (Builder $query) => $query->where('driver_id', $actor->driver?->getKey() ?? 0)
            )
            ->orderBy('start_datetime')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function visibleReservationsQuery(User $actor): Builder
    {
        return Reservation::query()
            ->with(['organization', 'requestedByUser', 'pickupLocation', 'destinationLocation', 'driver.user', 'vehicle', 'conflicts', 'councilDecision'])
            ->when(! $actor->hasAnyRole(['admin', 'agent_communal']), function (Builder $query) use ($actor): void {
                $query->where(function (Builder $query) use ($actor): void {
                    $query->where('requested_by_user_id', $actor->getKey())
                        ->orWhereHas('organization.users', fn (Builder $query) => $query->whereKey($actor->getKey()))
                        ->when(
                            $actor->hasRole('chauffeur') && $actor->driver !== null,
                            fn (Builder $query) => $query->orWhere('driver_id', $actor->driver->getKey())
                        );
                });
            });
    }
}
