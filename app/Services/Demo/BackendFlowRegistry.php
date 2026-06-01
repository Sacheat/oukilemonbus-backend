<?php

declare(strict_types=1);

namespace App\Services\Demo;

final class BackendFlowRegistry
{
    public function all(): array
    {
        return config('backend_inspector.flows', []);
    }

    public function find(?string $actionKey): ?array
    {
        if ($actionKey === null || trim($actionKey) === '') {
            return null;
        }

        $flows = $this->all();

        return $flows[$actionKey] ?? null;
    }

    public function has(string $actionKey): bool
    {
        return $this->find($actionKey) !== null;
    }

    public function scenarios(): array
    {
        return config('backend_inspector.scenarios', []);
    }

    public function scenario(?string $scenarioKey): ?array
    {
        if ($scenarioKey === null || trim($scenarioKey) === '') {
            return null;
        }

        $scenarios = $this->scenarios();

        return $scenarios[$scenarioKey] ?? null;
    }
}
