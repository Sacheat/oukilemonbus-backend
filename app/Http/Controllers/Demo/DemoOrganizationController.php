<?php

declare(strict_types=1);

namespace App\Http\Controllers\Demo;

use App\Services\Demo\DemoActorResolver;
use App\Services\Demo\DemoDataService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Liste des organisations dans la démo.
 * Les non-internes (responsable_organisation, user) ne voient que leurs propres organisations,
 * le filtrage est délégué à DemoDataService.
 */
final class DemoOrganizationController extends DemoBaseController
{
    public function __construct(
        DemoActorResolver $actorResolver,
        private readonly DemoDataService $demoData,
    ) {
        parent::__construct($actorResolver);
    }

    public function index(Request $request): View
    {
        $actor = $this->actor($request);
        $this->authorizeFor($actor, 'viewAny', \App\Models\Organization::class);

        $organizations = $this->demoData->organizations($actor);

        $this->recordInspectorView($request, 'organizations.index', [
            'status' => 'success',
            'message' => 'Ouverture de la liste des organisations.',
            'actor_id' => $actor->id,
            'organizations_count' => method_exists($organizations, 'total') ? $organizations->total() : $organizations->count(),
        ]);

        return view('demo.organizations.index', [
            ...$this->shared($request),
            'organizations' => $organizations,
        ]);
    }
}
