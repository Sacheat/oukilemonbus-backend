<?php

namespace App\Services\Planning;

use App\Jobs\GeneratePlanningExportJob;
use App\Models\PlanningExportRequest;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

class PlanningAsyncExportService
{
    public function requestExport(
        User $user,
        string $startDate,
        string $endDate,
        array $statuses = [],
        array $filters = []
    ): PlanningExportRequest {
        $request = PlanningExportRequest::query()->create([
            'requested_by_user_id' => $user->id,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'statuses' => $statuses,
            'filters' => $filters,
            'status' => 'pending',
            'requested_at' => now(),
        ]);

        GeneratePlanningExportJob::dispatch($request->id);

        return $request->refresh();
    }

    public function getForUser(User $user, int $id): PlanningExportRequest
    {
        $query = PlanningExportRequest::query()
            ->with('document')
            ->whereKey($id);

        if (! $user->hasAnyRole(['admin', 'agent_communal'])) {
            $query->where('requested_by_user_id', $user->id);
        }

        $request = $query->firstOrFail();

        if (
            ! $user->hasAnyRole(['admin', 'agent_communal'])
            && $request->requested_by_user_id !== $user->id
        ) {
            throw new AuthorizationException('Accès non autorisé à cette demande d’export.');
        }

        return $request;
    }
}
