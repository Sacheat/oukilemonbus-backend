<?php

declare(strict_types=1);

namespace App\Http\Controllers\Demo;

use App\Services\Demo\DemoActorResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class DemoActorController extends DemoBaseController
{
    public function __construct(DemoActorResolver $actorResolver)
    {
        parent::__construct($actorResolver);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
        ]);

        $this->actorResolver->set($request, (int) $validated['user_id']);

        return back()->with('status', 'Acteur de demonstration change.');
    }
}
