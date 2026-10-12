<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Recurrences\CancelRecurrenceRequest;
use App\Http\Requests\Recurrences\PreviewRecurrenceRequest;
use App\Http\Requests\Recurrences\StoreRecurrenceRequest;
use App\Http\Resources\RecurrenceResource;
use App\Models\Organization;
use App\Models\Recurrence;
use App\Models\Reservation;
use App\Services\Idempotency\ApiIdempotencyService;
use App\Services\Recurrences\RecurrenceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * Gère les séries de réservations récurrentes.
 * La Recurrence n'a pas de FK directe vers Organization ; l'organisation est déduite
 * depuis ses réservations enfants (recurrence → reservations → organization_id).
 */
class RecurrenceController extends Controller
{
    /** Relations des occurrences, identiques à GET /reservations/{id}. */
    private const OCCURRENCE_RELATIONS = ['organization', 'pickupLocation', 'destinationLocation', 'driver', 'vehicle', 'conflicts'];

    public function __construct(
        private readonly RecurrenceService $recurrenceService,
        private readonly ApiIdempotencyService $idempotencyService
    ) {}

    public function index(): AnonymousResourceCollection
    {
        $user = request()->user();

        $recurrences = Recurrence::query()
            ->when(
                // Les non-internes ne voient que les récurrences liées à leurs organisations.
                ! $user->hasAnyRole(['admin', 'agent_communal']),
                fn ($q) => $q->whereHas('reservations', function ($q) use ($user): void {
                    $q->whereIn('organization_id', $user->organizations()->select('organizations.id'));
                })
            )
            ->when(request('is_active') !== null, fn ($q) => $q->where('is_active', (bool) request('is_active')))
            ->withSummary()
            ->orderByDesc('created_at')
            ->paginate();

        return RecurrenceResource::collection($recurrences);
    }

    public function preview(PreviewRecurrenceRequest $request): JsonResponse
    {
        $organization = Organization::findOrFail($request->validated('organization_id'));
        // Réutilise la policy de création de réservation car créer une récurrence = créer plusieurs réservations.
        Gate::authorize('createForOrganization', [Reservation::class, $organization]);

        // Un agent ou un admin peut déroger au préavis de 15 jours (même règle qu'à la création)
        $preview = $this->recurrenceService->preview([
            ...$request->validated(),
            'skip_minimum_notice' => $request->user()->hasAnyRole(['admin', 'agent_communal']),
        ]);

        return response()->json(['data' => $preview]);
    }

    public function store(StoreRecurrenceRequest $request): JsonResponse
    {
        $organization = Organization::findOrFail($request->validated('organization_id'));
        Gate::authorize('createForOrganization', [Reservation::class, $organization]);

        $idempotencyKey = $request->header('Idempotency-Key');
        if (is_string($idempotencyKey) && $idempotencyKey !== '') {
            // Même mécanisme que POST /reservations : même clé + même corps → réponse initiale rejouée.
            $response = $this->idempotencyService->execute(
                user: $request->user(),
                key: $idempotencyKey,
                method: 'POST',
                routeName: (string) ($request->route()?->getName() ?? 'api.recurrences.store'),
                requestHash: hash('sha256', json_encode($request->validated(), JSON_THROW_ON_ERROR)),
                action: fn (): array => [
                    'status' => 201,
                    'body' => $this->createdResponseBody($request),
                ]
            );

            return response()->json($response['body'], $response['status']);
        }

        return response()->json($this->createdResponseBody($request), 201);
    }

    /**
     * Corps de la réponse 201 : la resource enrichie et, dans `meta`, le rapport de création
     * (trajets créés, dates ignorées au preview, dates en échec avec leur motif).
     */
    private function createdResponseBody(StoreRecurrenceRequest $request): array
    {
        $report = $this->recurrenceService->createWithReport($request->validated(), $request->user());

        $recurrence = $report['recurrence']
            ->load(['reservations' => fn ($q) => $q->orderBy('trip_date')->with(self::OCCURRENCE_RELATIONS)])
            ->loadSummary();

        return (new RecurrenceResource($recurrence))
            ->additional(['meta' => [
                'created_count' => $report['created_count'],
                'skipped_dates' => $report['skipped_dates'],
                'failed_dates'  => $report['failed_dates'],
            ]])
            ->response()
            ->getData(true);
    }

    public function show(Recurrence $recurrence): RecurrenceResource
    {
        $user = request()->user();

        if (! $user->hasAnyRole(['admin', 'agent_communal'])) {
            // Vérifie que l'utilisateur appartient à l'organisation de la récurrence.
            $organizationId = $recurrence->reservations()->value('organization_id');
            if ($organizationId === null || ! $user->organizations()->whereKey($organizationId)->exists()) {
                abort(403);
            }
        }

        return new RecurrenceResource(
            $recurrence
                ->load(['reservations' => fn ($q) => $q->orderBy('trip_date')->with(self::OCCURRENCE_RELATIONS)])
                ->loadSummary()
        );
    }

    public function cancel(CancelRecurrenceRequest $request, Recurrence $recurrence): JsonResponse
    {
        $user = $request->user();

        if (! $user->hasAnyRole(['admin', 'agent_communal'])) {
            $organizationId = $recurrence->reservations()->value('organization_id');
            if ($organizationId === null || ! $user->organizations()->whereKey($organizationId)->exists()) {
                abort(403, 'Vous ne pouvez pas annuler cette récurrence.');
            }
        }

        // Annule uniquement les occurrences encore actives (EN_ATTENTE, APPROUVE, CONFIRME).
        $cancelledIds = $this->recurrenceService->cancelRemaining(
            $recurrence,
            $user,
            $request->validated('reason')
        );

        return response()->json([
            'data' => [
                'cancelled_reservation_ids' => $cancelledIds,
                'cancelled_count'           => count($cancelledIds),
            ],
        ]);
    }
}