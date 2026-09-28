<?php

declare(strict_types=1);

namespace App\Tooling\Inspector\Services;

/**
 * Catalogue des flows métier documentés du Backend Inspector (config/tooling/inspector.php).
 *
 * Un flow décrit ce qu'une route API déclenche côté backend : contrôleur, Form Request,
 * service, tables touchées et effets. `find()` tolère les clés inconnues (retourne null).
 * Sans état : la config est relue à chaque appel.
 */
final class FlowRegistry
{
    public function all(): array
    {
        return config('tooling.inspector.flows', []);
    }

    public function find(?string $flowKey): ?array
    {
        if ($flowKey === null || trim($flowKey) === '') {
            return null;
        }

        return $this->all()[$flowKey] ?? null;
    }

    public function has(string $flowKey): bool
    {
        return $this->find($flowKey) !== null;
    }
}
