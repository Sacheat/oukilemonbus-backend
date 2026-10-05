<?php

declare(strict_types=1);

namespace App\Tooling\Toolbox\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Tooling\Toolbox\Services\FailedJobsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Jobs en échec de la file d'attente (/api/admin/tooling/queue/failed-jobs*) : liste, détail expurgé,
 * relance (queue:retry) et suppression (queue:forget / queue:flush). Seules routes du module qui agissent.
 */
final class FailedJobsController extends Controller
{
    public function __construct(
        private readonly FailedJobsService $failedJobs,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'queue' => ['sometimes', 'string', 'max:255'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
        ]);

        // Enveloppe { data, links, meta } des autres listes paginées ; chaque élément est déjà un tableau.
        return JsonResource::collection(
            $this->failedJobs
                ->paginate($validated['queue'] ?? null, (int) ($validated['per_page'] ?? 15))
                ->withQueryString()
        );
    }

    public function show(string $uuid): JsonResponse
    {
        $job = $this->failedJobs->find($uuid);

        abort_if($job === null, 404, 'Job en échec introuvable.');

        return response()->json(['data' => $job]);
    }

    public function retry(string $uuid): JsonResponse
    {
        abort_unless($this->failedJobs->exists($uuid), 404, 'Job en échec introuvable.');

        return response()->json(['data' => ['retried' => $this->failedJobs->retry($uuid)]], 202);
    }

    public function retryAll(): JsonResponse
    {
        return response()->json(['data' => ['retried' => $this->failedJobs->retry()]], 202);
    }

    public function destroy(string $uuid): JsonResponse
    {
        abort_unless($this->failedJobs->exists($uuid), 404, 'Job en échec introuvable.');

        return response()->json(['data' => ['deleted' => $this->failedJobs->delete($uuid)]]);
    }

    public function destroyAll(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'before' => ['sometimes', 'date'],
        ]);

        return response()->json(['data' => ['deleted' => $this->failedJobs->delete(before: $validated['before'] ?? null)]]);
    }
}
