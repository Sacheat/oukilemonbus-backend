<?php

declare(strict_types=1);

namespace App\Tooling\Inspector\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Tooling\Inspector\Http\Requests\ListInspectorEntriesRequest;
use App\Tooling\Inspector\Http\Resources\InspectorEntryResource;
use App\Tooling\Inspector\Models\InspectorEntry;
use App\Tooling\Inspector\Services\FlowRegistry;
use App\Tooling\Inspector\Services\UserInspectorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * API d'administration du Backend Inspector (/api/admin/tooling/inspector/*).
 * Lecture du journal, catalogue des flows, fiche d'un utilisateur et purge manuelle.
 */
final class InspectorController extends Controller
{
    public function __construct(
        private readonly FlowRegistry $flowRegistry,
        private readonly UserInspectorService $userInspector,
    ) {}

    public function status(): JsonResponse
    {
        return response()->json([
            'data' => [
                'enabled' => (bool) config('tooling.inspector.enabled', false),
                'retention_days' => (int) config('tooling.inspector.retention_days', 14),
                'recorded_methods' => (array) config('tooling.inspector.methods', []),
                'entries_count' => InspectorEntry::query()->count(),
                'oldest_entry_at' => InspectorEntry::query()->min('created_at'),
                'latest_entry_at' => InspectorEntry::query()->max('created_at'),
                'flows_count' => count($this->flowRegistry->all()),
            ],
        ]);
    }

    public function index(ListInspectorEntriesRequest $request): AnonymousResourceCollection
    {
        $filters = $request->validated();

        $entries = InspectorEntry::query()
            ->when(isset($filters['user_id']), fn ($q) => $q->where('user_id', $filters['user_id']))
            ->when(isset($filters['user_email']), fn ($q) => $q->where('user_email', 'like', '%'.$filters['user_email'].'%'))
            ->when(isset($filters['flow_key']), fn ($q) => $q->where('flow_key', $filters['flow_key']))
            ->when(isset($filters['route_name']), fn ($q) => $q->where('route_name', $filters['route_name']))
            ->when(isset($filters['method']), fn ($q) => $q->where('method', $filters['method']))
            ->when(isset($filters['outcome']), fn ($q) => $q->where('outcome', $filters['outcome']))
            ->when(isset($filters['http_status']), fn ($q) => $q->where('http_status', $filters['http_status']))
            ->when(isset($filters['from']), fn ($q) => $q->where('created_at', '>=', $filters['from']))
            ->when(isset($filters['to']), fn ($q) => $q->where('created_at', '<=', $filters['to']))
            ->latest('id')
            ->paginate($filters['per_page'] ?? 25);

        return InspectorEntryResource::collection($entries);
    }

    public function show(InspectorEntry $inspectorEntry): InspectorEntryResource
    {
        return (new InspectorEntryResource($inspectorEntry))->withFullFlow();
    }

    public function purge(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'before' => ['sometimes', 'date'],
        ]);

        $deleted = InspectorEntry::query()
            ->when(isset($validated['before']), fn ($q) => $q->where('created_at', '<', $validated['before']))
            ->delete();

        return response()->json(['data' => ['deleted' => $deleted]]);
    }

    public function flows(): JsonResponse
    {
        return response()->json([
            'data' => [
                'flows' => $this->flowRegistry->all(),
                'route_map' => config('tooling.inspector.route_map', []),
            ],
        ]);
    }

    public function flow(string $flowKey): JsonResponse
    {
        $flow = $this->flowRegistry->find($flowKey);

        abort_if($flow === null, 404, 'Flow inconnu.');

        return response()->json(['data' => ['key' => $flowKey, ...$flow]]);
    }

    public function user(User $user): JsonResponse
    {
        $entries = InspectorEntry::query()->where('user_id', $user->getKey());

        return response()->json([
            'data' => [
                ...$this->userInspector->inspect($user),
                'active' => (bool) $user->active,
                'email_verified_at' => $user->email_verified_at?->toISOString(),
                'inspector' => [
                    'entries_count' => (clone $entries)->count(),
                    'errors_count' => (clone $entries)->where('outcome', InspectorEntry::OUTCOME_ERROR)->count(),
                    'latest_entry_at' => (clone $entries)->max('created_at'),
                ],
            ],
        ]);
    }
}
