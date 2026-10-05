<?php

namespace App\Services\Organizations;

use App\Enums\OrganizationType;
use App\Enums\ReservationStatus;
use App\Models\Organization;
use App\Models\Reservation;
use App\Models\User;
use App\Services\Audit\DomainEventLogger;
use App\Services\Notifications\NotificationDispatchService;
use App\Services\Reservations\ReservationQuotaService;
use App\Services\Reservations\ReservationService;
use App\Support\LikeSearch;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Gère les organisations : liste filtrée, création, modification, bannissement.
 *
 * `prepareData()` déduit automatiquement `is_priority` depuis le type d'organisation
 * si `is_priority` n'est pas fourni explicitement — évite les incohérences entre type et priorité.
 *
 * Bannissement (admin) : l'organisation ne peut plus créer de réservation (ReservationPreviewService::
 * ensureOrganizationNotBanned). Au choix de l'admin, ses réservations à venir sont aussi annulées, une par une, par la
 * règle habituelle (ReservationService::cancel : motif, demandeur et chauffeur prévenus) ; celles qui ne peuvent pas
 * l'être sont renvoyées avec la raison. Les membres sont prévenus du bannissement et de la réactivation.
 * Création, modification, bannissement et réactivation sont tracés (DomainEventLogger).
 */
class OrganizationService
{
    /** Champs modifiables par un agent ou un admin */
    private const EDITABLE_FIELDS = [
        'name',
        'type',
        'official_email',
        'phone_contact',
        'name_contact',
        'location_id',
        'is_priority',
        'annual_quota',
        'proof_document_id',
    ];

    /** Réservations encore « en cours » (pas terminées) */
    public const ACTIVE_STATUSES = [
        ReservationStatus::EN_ATTENTE->value,
        ReservationStatus::EN_CONFLIT->value,
        ReservationStatus::APPROUVE->value,
        ReservationStatus::CONFIRME->value,
    ];

    public function __construct(
        private readonly DomainEventLogger $eventLogger,
        private readonly NotificationDispatchService $notificationDispatchService,
        private readonly ReservationService $reservationService
    ) {}

    /**
     * Liste paginée. Agent / admin : toutes ; autres : celles dont ils sont membres.
     * Filtres : q (chaque mot dans le nom, l'email officiel ou le contact), type, banned (0/1) ;
     * tri : nom (défaut), quota (les plus proches de leur limite d'abord, exemptées à la fin), activite
     * (le plus de réservations à venir d'abord). Comptes faits par la base (sous-requêtes), pas en mémoire.
     */
    public function list(User $user, array $filters): LengthAwarePaginator
    {
        $query = $this->withListCounts(Organization::query()->with('location'))
            ->when(
                ! $user->hasAnyRole(['admin', 'agent_communal']),
                fn (Builder $query) => $query->whereHas('users', fn (Builder $users) => $users->whereKey($user->getKey()))
            )
            ->when($filters['type'] ?? null, fn (Builder $query, string $type) => $query->where('type', $type))
            ->when(
                isset($filters['banned']),
                fn (Builder $query) => $query->where('is_banned', (bool) $filters['banned'])
            );

        foreach (LikeSearch::patterns($filters['q'] ?? null) as $like) {
            $query->where(fn (Builder $word) => $word
                ->where('name', 'like', $like)
                ->orWhere('official_email', 'like', $like)
                ->orWhere('name_contact', 'like', $like));
        }

        $sort = $filters['sort'] ?? 'nom';

        if ($sort === 'quota') {
            $exemptTypes = array_values(config('reservations.quota_exempt_types', []));

            if ($exemptTypes !== []) {
                // Exemptées à la fin
                $query->orderByRaw(
                    'CASE WHEN type IN ('.implode(',', array_fill(0, count($exemptTypes), '?')).') THEN 1 ELSE 0 END',
                    $exemptTypes
                );
            }

            // Part du quota utilisée, la plus grande d'abord (quota 0 : déjà plein)
            $query->orderByRaw('CASE WHEN annual_quota = 0 THEN 1000000 ELSE quota_used_count * 1.0 / annual_quota END DESC');
        } elseif ($sort === 'activite') {
            $query->orderByDesc('upcoming_reservations_count');
        }

        return $query->orderBy('name')->orderBy('id')->paginate((int) ($filters['per_page'] ?? 15));
    }

    /**
     * Ajoute les comptes de la liste et du détail : membres, réservations à venir, quota de l'année.
     * `$query` : requête d'organisations ou relation (ex. organisations d'un utilisateur dans /auth/me).
     */
    public function withListCounts(Builder|Relation $query): Builder|Relation
    {
        $today = Reservation::localToday();
        $year = (int) substr($today, 0, 4);

        return $query->withCount([
            'users as members_count',
            'reservations as upcoming_reservations_count' => fn (Builder $reservations) => $reservations
                ->whereIn('status', self::ACTIVE_STATUSES)
                ->whereDate('trip_date', '>=', $today),
            'reservations as quota_used_count' => fn (Builder $reservations) => $reservations
                ->whereIn('status', ReservationQuotaService::COUNTED_STATUSES)
                ->whereYear('trip_date', $year),
        ]);
    }

    public function create(array $data, User $actor): Organization
    {
        $data = $this->prepareData($data);
        $data['annual_quota'] ??= (int) config('reservations.annual_quota', 5);

        $organization = Organization::create(Arr::only($data, self::EDITABLE_FIELDS))->refresh();

        $this->eventLogger->log(
            aggregateType: 'organization',
            aggregateId: $organization->id,
            eventType: 'organization.created',
            actor: $actor,
            newValues: $this->auditValues($organization)
        );

        return $organization;
    }

    public function update(Organization $organization, array $data, User $actor): Organization
    {
        $data = $this->prepareData($data);
        $before = $this->auditValues($organization);

        $organization->update(Arr::only($data, self::EDITABLE_FIELDS));
        $organization->refresh();

        $after = $this->auditValues($organization);
        $changed = array_keys(array_filter(
            $after,
            fn ($value, string $field): bool => (string) ($value ?? '') !== (string) ($before[$field] ?? ''),
            ARRAY_FILTER_USE_BOTH
        ));

        if ($changed !== []) {
            $this->eventLogger->log(
                aggregateType: 'organization',
                aggregateId: $organization->id,
                eventType: 'organization.updated',
                actor: $actor,
                oldValues: Arr::only($before, $changed),
                newValues: Arr::only($after, $changed)
            );
        }

        return $organization;
    }

    /**
     * Bannit l'organisation ; si `$cancelUpcoming`, annule aussi ses réservations à venir.
     *
     * @return array{organization: Organization, cancelled: list<int>, skipped: list<array{id: int, reason: string}>}
     */
    public function ban(Organization $organization, string $reason, User $actor, bool $cancelUpcoming = false): array
    {
        if ($organization->is_banned) {
            throw ValidationException::withMessages(['organization' => 'Cette organisation est déjà bannie.']);
        }

        DB::transaction(function () use ($organization, $reason, $actor, $cancelUpcoming): void {
            $organization->update(['is_banned' => true, 'banned_reason' => $reason]);

            $this->eventLogger->log(
                aggregateType: 'organization',
                aggregateId: $organization->id,
                eventType: 'organization.banned',
                actor: $actor,
                newValues: ['banned_reason' => $reason, 'cancel_upcoming' => $cancelUpcoming]
            );
        });

        [$cancelled, $skipped] = $cancelUpcoming ? $this->cancelUpcomingReservations($organization, $reason, $actor) : [[], []];

        $this->notifyMembers(
            $organization,
            'organization.banned',
            'Organisation suspendue',
            "L’organisation « {$organization->name} » ne peut plus réserver le bus communal."
                .($cancelled !== [] ? ' Ses réservations à venir ont été annulées.' : ''),
            $reason
        );

        return ['organization' => $organization->refresh(), 'cancelled' => $cancelled, 'skipped' => $skipped];
    }

    public function unban(Organization $organization, User $actor): Organization
    {
        if (! $organization->is_banned) {
            throw ValidationException::withMessages(['organization' => 'Cette organisation n’est pas bannie.']);
        }

        $previousReason = $organization->banned_reason;
        $organization->update(['is_banned' => false, 'banned_reason' => null]);

        $this->eventLogger->log(
            aggregateType: 'organization',
            aggregateId: $organization->id,
            eventType: 'organization.unbanned',
            actor: $actor,
            oldValues: ['banned_reason' => $previousReason]
        );

        $this->notifyMembers(
            $organization,
            'organization.unbanned',
            'Organisation réactivée',
            "L’organisation « {$organization->name} » peut de nouveau réserver le bus communal."
        );

        return $organization->refresh();
    }

    /**
     * Annule une par une les réservations à venir, par la règle habituelle. Une réservation qui ne peut pas l'être
     * (trajet déjà réalisé, statut final…) est gardée et renvoyée avec la raison.
     *
     * @return array{0: list<int>, 1: list<array{id: int, reason: string}>}
     */
    private function cancelUpcomingReservations(Organization $organization, string $reason, User $actor): array
    {
        $cancelled = [];
        $skipped = [];

        $organization->reservations()
            ->whereIn('status', self::ACTIVE_STATUSES)
            ->whereDate('trip_date', '>=', Reservation::localToday())
            ->orderBy('trip_date')
            ->orderBy('departure_time')
            ->get()
            ->each(function (Reservation $reservation) use ($reason, $actor, &$cancelled, &$skipped): void {
                try {
                    $this->reservationService->cancel($reservation, $actor, "Organisation bannie : {$reason}");
                    $cancelled[] = $reservation->id;
                } catch (ValidationException $exception) {
                    $skipped[] = [
                        'id' => $reservation->id,
                        'reason' => collect($exception->errors())->flatten()->first() ?? $exception->getMessage(),
                    ];
                }
            });

        return [$cancelled, $skipped];
    }

    private function notifyMembers(Organization $organization, string $eventKey, string $title, string $message, ?string $reason = null): void
    {
        $members = $organization->users()->where('active', true)->get();

        if ($members->isEmpty()) {
            return;
        }

        $this->notificationDispatchService->dispatch(
            users: $members,
            eventKey: $eventKey,
            category: 'reservation_updates',
            title: $title,
            message: $reason ? "{$message} Motif : {$reason}" : $message,
            priority: 'high',
            urgent: true,
            reason: $reason,
            link: ['path' => '/espace/organisations', 'label' => 'Voir mes organisations']
        );
    }

    /** Valeurs tracées dans l'historique (les champs modifiables) */
    private function auditValues(Organization $organization): array
    {
        $values = Arr::only($organization->attributesToArray(), self::EDITABLE_FIELDS);
        $values['type'] = $organization->type?->value;

        return $values;
    }

    private function prepareData(array $data): array
    {
        if (array_key_exists('type', $data) && ! array_key_exists('is_priority', $data)) {
            $type = $data['type'] instanceof OrganizationType
                ? $data['type']
                : OrganizationType::from($data['type']);

            $data['is_priority'] = $type->isPriorityByDefault();
        }

        return $data;
    }
}
