<?php

declare(strict_types=1);

namespace App\Http\Controllers\Demo;

use App\Services\Demo\DemoActorResolver;
use App\Services\Demo\DemoDataService;
use Illuminate\Http\Request;
use Illuminate\View\View;

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

        return view('demo.organizations.index', [
            ...$this->shared($request),
            'organizations' => $this->demoData->organizations($actor),
        ]);
    }
}
