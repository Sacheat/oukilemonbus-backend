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

        $enabledKey = $sessionKeys['enabled'] ?? 'backend_inspector.enabled';
        $scenarioKey = $sessionKeys['current_scenario'] ?? 'backend_inspector.current_scenario';
        $lastActionKey = $sessionKeys['last_action'] ?? 'backend_inspector.last_action';
        $lastContextKey = $sessionKeys['last_context'] ?? 'backend_inspector.last_context';
        $lastRecordedAtKey = $sessionKeys['last_recorded_at'] ?? 'backend_inspector.last_recorded_at';

        $isGloballyEnabled = (bool) config('backend_inspector.enabled', false);
        $isSessionEnabled = (bool) $request->session()->get($enabledKey, true);

        $currentScenarioKey = $request->session()->get($scenarioKey);
        $lastAction = $request->session()->get($lastActionKey);
        $lastContext = $request->session()->get($lastContextKey, []);
        $lastRecordedAt = $request->session()->get($lastRecordedAtKey);

        return [
            'enabled' => $isGloballyEnabled && $isSessionEnabled,
            'global_enabled' => $isGloballyEnabled,
            'session_enabled' => $isSessionEnabled,

            'actor' => $this->userInspector->inspect($actor),

            'scenario_key' => $currentScenarioKey,
            'scenario' => $this->flowRegistry->scenario($currentScenarioKey),

            'last_action_key' => $lastAction,
            'last_action' => $this->flowRegistry->find($lastAction),

            'last_context' => $lastContext,
            'last_recorded_at' => $lastRecordedAt,
        ];
    }
}
