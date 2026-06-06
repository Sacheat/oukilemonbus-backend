<?php

declare(strict_types=1);

namespace App\Services\Demo;

/**
 * Registre des flux et scénarios du Backend Inspector, chargé depuis config/backend_inspector.php.
 *
 * `all()` retourne le dictionnaire complet des flows (actionKey → métadonnées).
 * `find()` résout une actionKey en flux ; retourne null si inconnue (tolérant aux clés fantômes).
 * `scenarios()` retourne les scénarios prédéfinis pour le panneau de démonstration.
 *
 * Cette classe n'a pas d'état : elle relit la config à chaque appel.
 * Les flux sont peuplés dynamiquement par BackendInspectorMiddleware (clé = route name mappée).
 */
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
