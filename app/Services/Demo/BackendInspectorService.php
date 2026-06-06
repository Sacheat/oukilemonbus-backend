<?php

declare(strict_types=1);

namespace App\Services\Demo;

use App\Models\User;
use Illuminate\Http\Request;

final class BackendInspectorService
{
    public function __construct(
        private readonly BackendFlowRegistry $flowRegistry,
        private readonly UserInspectorService $userInspector,
    ) {
    }

    public function state(Request $request, ?User $actor = null): array
    {
        $sessionKeys = config('backend_inspector.session_keys', []);

        $enabledKey        = $sessionKeys['enabled']          ?? 'backend_inspector.enabled';
        $scenarioKey       = $sessionKeys['current_scenario'] ?? 'backend_inspector.current_scenario';
        $lastActionKey     = $sessionKeys['last_action']      ?? 'backend_inspector.last_action';
        $lastContextKey    = $sessionKeys['last_context']     ?? 'backend_inspector.last_context';
        $lastRecordedAtKey = $sessionKeys['last_recorded_at'] ?? 'backend_inspector.last_recorded_at';
        $historyKey        = $sessionKeys['history']          ?? 'backend_inspector.history';

        $isGloballyEnabled = (bool) config('backend_inspector.enabled', false);
        $isSessionEnabled  = (bool) $request->session()->get($enabledKey, true);

        $currentScenarioKey = $request->session()->get($scenarioKey);
        $lastAction         = $request->session()->get($lastActionKey);
        $lastContext        = $request->session()->get($lastContextKey, []);
        $lastRecordedAt     = $request->session()->get($lastRecordedAtKey);
        $rawHistory         = $request->session()->get($historyKey, []);

        $history = array_map(fn (array $item): array => [
            'action_key'  => $item['action_key'],
            'action'      => $this->flowRegistry->find($item['action_key']),
            'context'     => $item['context'] ?? [],
            'recorded_at' => $item['recorded_at'],
        ], $rawHistory);

        return [
            'enabled'        => $isGloballyEnabled && $isSessionEnabled,
            'global_enabled' => $isGloballyEnabled,
            'session_enabled' => $isSessionEnabled,

            'actor' => $this->userInspector->inspect($actor),

            'scenario_key' => $currentScenarioKey,
            'scenario'     => $this->flowRegistry->scenario($currentScenarioKey),

            'last_action_key' => $lastAction,
            'last_action'     => $this->flowRegistry->find($lastAction),

            'last_context'    => $lastContext,
            'last_recorded_at' => $lastRecordedAt,

            'history' => $history,
        ];
    }
}
