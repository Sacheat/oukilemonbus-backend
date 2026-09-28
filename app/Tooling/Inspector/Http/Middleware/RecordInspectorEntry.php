<?php

declare(strict_types=1);

namespace App\Tooling\Inspector\Http\Middleware;

use App\Tooling\Inspector\Services\InspectorRecorder;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Enregistre chaque requête API mutante dans le journal du Backend Inspector.
 * Ajouté au groupe `api` par ToolingServiceProvider ; inactif si tooling.inspector.enabled = false.
 * Un échec d'enregistrement est journalisé mais ne modifie jamais la réponse renvoyée au client.
 */
final class RecordInspectorEntry
{
    public function __construct(
        private readonly InspectorRecorder $recorder,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! config('tooling.inspector.enabled', false)) {
            return $next($request);
        }

        $startedAt = microtime(true);

        // Capturé avant l'exécution : après un logout, l'utilisateur n'est plus résolvable.
        $actorBefore = $request->user();

        $response = $next($request);

        if (! $this->recorder->shouldRecord($request)) {
            return $response;
        }

        try {
            // Après un login, l'acteur n'est connu qu'une fois la requête traitée.
            $this->recorder->record($request, $response, $request->user() ?? $actorBefore, $startedAt);
        } catch (Throwable $exception) {
            Log::warning('Backend Inspector : échec d\'enregistrement', [
                'route' => $request->route()?->getName(),
                'error' => $exception->getMessage(),
            ]);
        }

        return $response;
    }
}
