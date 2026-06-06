<?php

declare(strict_types=1);

namespace App\Http\Controllers\Demo;

use App\Services\Demo\DemoActorResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Permet de changer l'acteur de démonstration (le rôle joué dans l'interface).
 * L'acteur précédent est enregistré dans le contexte de l'inspecteur pour traçabilité.
 */
final class DemoActorController extends DemoBaseController
{
    public function __construct(DemoActorResolver $actorResolver)
    {
        parent::__construct($actorResolver);
    }

    public function update(Request $request): RedirectResponse
    {
        $previousActor = $this->actor($request);

        $validated = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
        ]);

        $this->actorResolver->set($request, (int) $validated['user_id']);

        $this->recordInspectorAction($request, 'demo.actor.changed', [
            'status' => 'success',
            'message' => 'Changement de l’acteur de démonstration.',
            'previous_actor_id' => $previousActor->id,
            'previous_actor_email' => $previousActor->email,
            'new_actor_id' => (int) $validated['user_id'],
        ]);

        $request->session()->put('backend_inspector.skip_next_view_recording', true);

        return back()->with('status', 'Acteur de demonstration change.');
    }
}
