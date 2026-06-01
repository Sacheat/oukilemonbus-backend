<?php

declare(strict_types=1);

namespace App\Http\Controllers\Demo;

use App\Services\Demo\DemoActorResolver;
use App\Services\Planning\PlanningService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class DemoPlanningController extends DemoBaseController
{
    public function __construct(
        DemoActorResolver $actorResolver,
        private readonly PlanningService $planningService,
    ) {
        parent::__construct($actorResolver);
    }

    public function __invoke(Request $request): View
    {
        $actor = $this->actor($request);
        $this->authorizeFor($actor, 'viewAny', \App\Models\Reservation::class);

        $startDate = $request->input('start_date', CarbonImmutable::today()->subDays(7)->toDateString());
        $endDate = $request->input('end_date', CarbonImmutable::today()->addDays(21)->toDateString());
        $includeAvailability = $actor->hasAnyRole(['admin', 'agent_communal']);

        $this->recordInspectorView($request, 'planning.index', [
            'status' => 'success',
            'message' => 'Ouverture du planning.',
            'actor_id' => $actor->id,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'include_availability' => $includeAvailability,
        ]);

        return view('demo.planning', [
            ...$this->shared($request),
            'startDate' => $startDate,
            'endDate' => $endDate,
            'includeAvailability' => $includeAvailability,
            'items' => $this->planningService->forPeriod($actor, $startDate, $endDate, $includeAvailability),
        ]);
    }
}
