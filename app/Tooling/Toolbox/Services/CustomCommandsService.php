<?php

declare(strict_types=1);

namespace App\Tooling\Toolbox\Services;

final class CustomCommandsService
{
    /**
     * Retourne la liste des commandes custom depuis le config.
     */
    public function list(): array
    {
        return (array) config('tooling.toolbox.custom_commands', []);
    }

    /**
     * Retourne une commande par son index dans la liste.
     */
    public function get(int $index): ?array
    {
        $commands = $this->list();

        return $commands[$index] ?? null;
    }

    /**
     * Indique si la commande nécessite une interaction avant d'être lancée.
     * (ex: route-sheets:generate-daily qui demande une date optionnelle)
     */
    public function isInteractive(int $index): bool
    {
        $command = $this->get($index);

        return (bool) ($command['interactive'] ?? false);
    }
}
