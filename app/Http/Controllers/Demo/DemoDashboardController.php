<?php

declare(strict_types=1);

namespace App\Http\Controllers\Demo;

use App\Models\Document;
use App\Models\OrgRegistrationRequest;
use App\Models\Organization;
use App\Models\Reservation;
use App\Models\User;
use App\Models\UserOrgRequest;
use App\Services\Demo\DemoActorResolver;
use App\Services\Demo\DemoDataService;
use App\Services\Notifications\NotificationHealthService;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class DemoDashboardController extends DemoBaseController
{
    public function __construct(
        DemoActorResolver $actorResolver,
        private readonly DemoDataService $demoData,
        private readonly NotificationHealthService $notificationHealthService,
    ) {
        parent::__construct($actorResolver);
    }

    public function __invoke(Request $request): View
    {
        $actor = $this->actor($request);
        $health = $this->notificationHealthService->summary();

        return view('demo.dashboard', [
            ...$this->shared($request),
            'stats' => [
                'reservations_visibles' => $this->demoData->visibleReservationsQuery($actor)->count(),
                'organisations_visibles' => $actor->can('organizations.view') ? $this->demoData->organizations($actor, 1)->total() : 0,
                'notifications_non_lues' => $actor->unreadNotifications()->count(),
                'demandes_org_en_attente' => OrgRegistrationRequest::query()->where('status', 'en_attente')->count(),
                'demandes_rattachement_en_attente' => UserOrgRequest::query()->where('status', 'en_attente')->count(),
                'livraisons_notif_ko_24h' => $health['deliveries_failed_last_24h'] ?? 0,
            ],
            'globalStats' => [
                'users' => User::query()->count(),
                'organizations' => Organization::query()->count(),
                'reservations' => Reservation::query()->count(),
                'documents' => Document::query()->count(),
            ],
            'latestReservations' => $this->demoData->visibleReservationsQuery($actor)
                ->orderByDesc('trip_date')
                ->orderBy('departure_time')
                ->limit(6)
                ->get(),
            'latestNotifications' => $actor->notifications()->limit(5)->get(),
            'availableActions' => $this->availableActions($actor),
        ]);
    }

    private function availableActions(User $actor): array
    {
        $actions = [];

        foreach ([
            'reservations.create' => 'Creer une reservation',
            'org-registration-requests.create' => 'Demander la creation d une organisation',
            'user-org-requests.create' => 'Demander un rattachement organisation',
            'reservations.approve' => 'Approuver des reservations',
            'reservations.assign' => 'Affecter chauffeur et vehicule',
            'documents.generate' => 'Generer des documents',
            'notifications.monitor' => 'Surveiller les notifications',
            'users.create' => 'Creer des utilisateurs',
        ] as $permission => $label) {
            if ($actor->can($permission)) {
                $actions[] = $label;
            }
        }

        return $actions;
    }
}
