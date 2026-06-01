<?php

namespace App\Http\Controllers\Demo;

use App\Enums\DocumentType;
use App\Enums\RequestStatus;
use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\Driver;
use App\Models\NotificationDelivery;
use App\Models\OrgRegistrationRequest;
use App\Models\Organization;
use App\Models\Reservation;
use App\Models\User;
use App\Models\UserOrgRequest;
use App\Models\Vehicle;
use App\Services\Documents\RouteSheetDocumentService;
use App\Services\Notifications\NotificationHealthService;
use App\Services\OrgRegistrationRequests\OrgRegistrationRequestService;
use App\Services\Planning\PlanningService;
use App\Services\Reservations\ReservationAssignmentService;
use App\Services\Reservations\ReservationService;
use App\Services\UserOrgRequests\UserOrgRequestService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Throwable;

class DemoController extends Controller
{
    public function __construct(
        private readonly PlanningService $planningService,
        private readonly ReservationService $reservationService,
        private readonly ReservationAssignmentService $assignmentService,
        private readonly RouteSheetDocumentService $routeSheetDocumentService,
        private readonly NotificationHealthService $notificationHealthService,
        private readonly OrgRegistrationRequestService $orgRegistrationRequestService,
        private readonly UserOrgRequestService $userOrgRequestService
    ) {}

    public function dashboard(Request $request): View
    {
        $actor = $this->actor($request);
        $health = $this->notificationHealthService->summary();
        // Enregistrer une action de visualisation du dashboard pour le backend inspector, avec un contexte contenant des statistiques sur le système
        $this->recordInspectorAction($request, 'demo.dashboard.viewed', [
            'status' => 'success',
            'message' => 'Ouverture du tableau de bord.',
            'actor_id' => $actor->id,
        ]);

        return view('demo.dashboard', [
            ...$this->shared($request),
            'actor' => $actor,
            'stats' => [
                'users' => User::query()->count(),
                'organizations' => Organization::query()->count(),
                'reservations' => Reservation::query()->count(),
                'documents' => Document::query()->count(),
                'unread_notifications' => $actor->unreadNotifications()->count(),
                'failed_deliveries_24h' => $health['deliveries_failed_last_24h'] ?? 0,
            ],
            'latestReservations' => Reservation::query()
                ->with(['organization', 'driver.user', 'vehicle', 'conflicts'])
                ->latest('trip_date')
                ->limit(6)
                ->get(),
            'latestNotifications' => $actor->notifications()->limit(5)->get(),
        ]);
    }

    public function setActor(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
        ]);

        $request->session()->put('demo_user_id', $validated['user_id']);

        $this->recordInspectorAction(
            $request,
            'demo.actor.changed',
            [
                'status' => 'success',
                'message' => 'Acteur de démonstration changé.',
                'user_id' => $validated['user_id'],
            ]
        );

        return back()->with('status', 'Acteur de demonstration change.');
    }

    public function reservations(Request $request): View
    {
        $this->recordInspectorAction($request, 'reservations.index', [
            'status' => 'success',
            'message' => 'Ouverture de la liste des réservations.',
            'status_filter' => $request->input('status'),
        ]);

        return view('demo.reservations', [
            ...$this->shared($request),
            'reservations' => Reservation::query()
                ->with(['organization', 'requestedByUser', 'pickupLocation', 'destinationLocation', 'driver.user', 'vehicle', 'conflicts'])
                ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
                ->orderByDesc('trip_date')
                ->orderBy('departure_time')
                ->paginate(12)
                ->withQueryString(),
            'drivers' => Driver::query()->with('user')->where('is_active', true)->orderBy('id')->get(),
            'vehicles' => Vehicle::query()->where('is_active', true)->orderBy('id')->get(),
        ]);
    }

    public function autoAssign(Request $request, Reservation $reservation): RedirectResponse
    {
        return $this->runAction($request, function () use ($request, $reservation): void {
            $this->assignmentService->autoAssign($reservation, $this->actor($request));
        }, 'Affectation automatique effectuee.');
    }

    public function assign(Request $request, Reservation $reservation): RedirectResponse
    {
        $validated = $request->validate([
            'driver_id' => ['nullable', 'integer', 'exists:drivers,id'],
            'vehicle_id' => ['nullable', 'integer', 'exists:vehicles,id'],
        ]);

        $payload = array_filter([
            'driver_id' => $validated['driver_id'] ?? null,
            'vehicle_id' => $validated['vehicle_id'] ?? null,
            'reason' => 'Affectation depuis la demo backend',
        ], fn ($value) => $value !== null && $value !== '');

        return $this->runAction($request, function () use ($request, $reservation, $payload): void {
            $this->assignmentService->assign($reservation, $payload, $this->actor($request));
        }, 'Affectation manuelle effectuee.');
    }

    public function approve(Request $request, Reservation $reservation): RedirectResponse
    {
        return $this->runAction(
            $request,
            function () use ($request, $reservation): void {
                $this->reservationService->approve(
                    $reservation,
                    $this->actor($request),
                    'Validation depuis la demo backend'
                );
            },
            'Reservation approuvee.',
            'reservations.approve',
            [
                'reservation_id' => $reservation->id,
                'current_status' => $reservation->status?->value ?? $reservation->status,
            ]
        );
    }

    public function confirm(Request $request, Reservation $reservation): RedirectResponse
    {
        return $this->runAction($request, function () use ($request, $reservation): void {
            $this->reservationService->confirm($reservation, $this->actor($request));
        }, 'Reservation confirmee.');
    }

    public function cancel(Request $request, Reservation $reservation): RedirectResponse
    {
        return $this->runAction($request, function () use ($request, $reservation): void {
            $this->reservationService->cancel($reservation, $this->actor($request), 'Annulation depuis la demo backend');
        }, 'Reservation annulee.');
    }

    public function planning(Request $request): View
    {
        $actor = $this->actor($request);
        $startDate = $request->input('start_date', CarbonImmutable::today()->subDays(7)->toDateString());
        $endDate = $request->input('end_date', CarbonImmutable::today()->addDays(21)->toDateString());
        // Enregistrer une action de visualisation du planning pour le backend inspector, avec un contexte contenant les filtres appliqués
        $this->recordInspectorAction($request, 'planning.index', [
            'status' => 'success',
            'message' => 'Ouverture du planning.',
            'actor_id' => $actor->id,
            'start_date' => $startDate,
            'end_date' => $endDate,
        ]);

        return view('demo.planning', [
            ...$this->shared($request),
            'startDate' => $startDate,
            'endDate' => $endDate,
            'items' => $this->planningService->forPeriod($actor, $startDate, $endDate, true),
        ]);
    }

    public function documents(Request $request): View
    {
        // Enregistrer une action de visualisation de la page documents pour le backend inspector, avec un contexte optionnel pour fournir des détails sur l'action
        $this->recordInspectorAction($request, 'documents.index', [
            'status' => 'success',
            'message' => 'Ouverture de la page documents.',
        ]);

        return view('demo.documents', [
            ...$this->shared($request),
            'documents' => Document::query()
                ->with(['reservation.organization', 'user'])
                ->latest()
                ->paginate(12),
            'vehicles' => Vehicle::query()->where('is_active', true)->orderBy('id')->get(),
        ]);
    }

    public function generateRouteSheet(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'start_date' => ['required_without:vehicle_id', 'date'],
            'end_date' => ['required_without:vehicle_id', 'date', 'after_or_equal:start_date'],
            'service_date' => ['required_with:vehicle_id', 'nullable', 'date'],
            'vehicle_id' => ['nullable', 'integer', 'exists:vehicles,id'],
        ]);

        return $this->runAction($request, function () use ($validated): void {
            if (! empty($validated['vehicle_id'])) {
                $this->routeSheetDocumentService->generateForServiceDateVehicle(
                    $validated['service_date'],
                    (int) $validated['vehicle_id']
                );

                return;
            }

            $this->routeSheetDocumentService->generateForPeriod(
                $validated['start_date'],
                $validated['end_date']
            );
        }, 'Feuille de route generee.');
    }

    public function downloadDocument(Document $document)
    {
        abort_unless($document->is_available, 404);
        abort_unless(Storage::disk('local')->exists($document->file_path), 404);

        return Storage::disk('local')->download(
            $document->file_path,
            $document->file_name,
            ['Content-Type' => $document->mime_type]
        );
    }

    public function notifications(Request $request): View
    {
        $actor = $this->actor($request);
        // Enregistrer une action de visualisation des notifications pour le backend inspector, avec un contexte contenant des statistiques sur les notifications de l'utilisateur
        $this->recordInspectorAction($request, 'notifications.index', [
            'status' => 'success',
            'message' => 'Ouverture des notifications.',
            'actor_id' => $actor->id,
        ]);

        return view('demo.notifications', [
            ...$this->shared($request),
            'notifications' => $actor->notifications()->paginate(15),
            'deliveries' => NotificationDelivery::query()
                ->where('notifiable_type', User::class)
                ->where('notifiable_id', $actor->id)
                ->latest()
                ->limit(15)
                ->get(),
            'health' => $this->notificationHealthService->summary(),
        ]);
    }

    public function markNotificationAsRead(Request $request, string $notificationId): RedirectResponse
    {
        $notification = $this->actor($request)->notifications()->whereKey($notificationId)->firstOrFail();
        $notification->markAsRead();

        return back()->with('status', 'Notification marquee comme lue.');
    }

    public function requests(Request $request): View
    {
        // Enregistrer une action de visualisation des demandes administratives pour le backend inspector, avec un contexte optionnel pour fournir des détails sur l'action
        $this->recordInspectorAction($request, 'requests.index', [
            'status' => 'success',
            'message' => 'Ouverture des demandes administratives.',
        ]);

        return view('demo.requests', [
            ...$this->shared($request),
            'orgRequests' => OrgRegistrationRequest::query()
                ->with(['requestedByUser', 'location'])
                ->latest()
                ->limit(20)
                ->get(),
            'userOrgRequests' => UserOrgRequest::query()
                ->with(['requestedByUser', 'organization'])
                ->latest()
                ->limit(20)
                ->get(),
        ]);
    }

    public function approveOrgRequest(Request $request, OrgRegistrationRequest $orgRegistrationRequest): RedirectResponse
    {
        return $this->runAction($request, function () use ($request, $orgRegistrationRequest): void {
            $this->orgRegistrationRequestService->approve($orgRegistrationRequest, $this->actor($request));
        }, 'Demande de creation approuvee.');
    }

    public function rejectOrgRequest(Request $request, OrgRegistrationRequest $orgRegistrationRequest): RedirectResponse
    {
        return $this->runAction($request, function () use ($request, $orgRegistrationRequest): void {
            $this->orgRegistrationRequestService->reject($orgRegistrationRequest, $this->actor($request), 'Refus depuis la demo backend');
        }, 'Demande de creation refusee.');
    }

    public function approveUserOrgRequest(Request $request, int $requestId): RedirectResponse
    {
        $userOrgRequest = UserOrgRequest::query()->findOrFail($requestId);

        return $this->runAction($request, function () use ($request, $userOrgRequest): void {
            $this->userOrgRequestService->approve($userOrgRequest, $this->actor($request));
        }, 'Demande de rattachement approuvee.');
    }

    public function rejectUserOrgRequest(Request $request, int $requestId): RedirectResponse
    {
        $userOrgRequest = UserOrgRequest::query()->findOrFail($requestId);

        return $this->runAction($request, function () use ($request, $userOrgRequest): void {
            $this->userOrgRequestService->reject($userOrgRequest, $this->actor($request), 'Refus depuis la demo backend');
        }, 'Demande de rattachement refusee.');
    }

    private function actor(Request $request): User
    {
        $sessionUserId = $request->session()->get('demo_user_id');
        $sessionUser = $sessionUserId ? User::query()->whereKey($sessionUserId)->first() : null;

        if ($sessionUser instanceof User && $sessionUser->active) {
            return $sessionUser;
        }

        $defaultUser = User::query()
            ->where('active', true)
            ->whereHas('roles', fn ($query) => $query->whereIn('name', ['admin', 'agent_communal']))
            ->first()
            ?? User::query()->where('active', true)->first();

        abort_if($defaultUser === null, 503, 'Aucun utilisateur actif disponible pour la demo.');

        $request->session()->put('demo_user_id', $defaultUser->id);

        return $defaultUser;
    }

    // Données partagées entre plusieurs vues de la demo
    private function shared(Request $request): array
    {
        return [
            'demoUsers' => User::query()->with('roles')->where('active', true)->orderBy('id')->get(),
            'actor' => $this->actor($request),
            'pendingOrgRequests' => OrgRegistrationRequest::query()->where('status', RequestStatus::EN_ATTENTE->value)->count(),
            'pendingUserOrgRequests' => UserOrgRequest::query()->where('status', RequestStatus::EN_ATTENTE->value)->count(),
            'routeSheetType' => DocumentType::FEUILLE_ROUTE->value,
        ];
    }

    // Helper pour factoriser l'execution d'une action avec gestion des erreurs et enregistrement pour le backend inspector
    private function runAction(Request $request, callable $action, string $successMessage, ?string $inspectorAction = null, array $inspectorContext = [] ): RedirectResponse
    {
        try {
            $action();

            $this->recordInspectorAction(
                $request,
                $inspectorAction,
                array_merge($inspectorContext, [
                    'status' => 'success',
                    'message' => $successMessage,
                ])
            );

            return back()->with('status', $successMessage);
        } catch (Throwable $exception) {
            report($exception);

            $this->recordInspectorAction(
                $request,
                $inspectorAction,
                array_merge($inspectorContext, [
                    'status' => 'error',
                    'message' => $exception->getMessage(),
                ])
            );

            return back()
                ->withInput()
                ->with('error', $exception->getMessage());
        }
    }

    // Enregistre une action pour le backend inspector, avec un contexte optionnel pour fournir des détails sur l'action
    private function recordInspectorAction(Request $request,?string $actionKey,array $context = []): void
    {
        if ($actionKey === null) {
            return;
        }

        if (!config('backend_inspector.enabled', false)) {
            return;
        }
        // Enregistrer l'action dans la session pour que le backend inspector puisse la récupérer et l'afficher
        session([
            'backend_inspector.last_action' => $actionKey,
            'backend_inspector.last_context' => $context,
            'backend_inspector.last_recorded_at' => now()->toDateTimeString(),
        ]);
    }
}
