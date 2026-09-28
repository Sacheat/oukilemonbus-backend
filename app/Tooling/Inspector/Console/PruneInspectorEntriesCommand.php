<?php

declare(strict_types=1);

namespace App\Tooling\Inspector\Console;

use App\Tooling\Inspector\Models\InspectorEntry;
use Illuminate\Console\Command;

/**
 * Supprime les entrées du Backend Inspector plus anciennes que la durée de rétention.
 * Planifiée quotidiennement par ToolingServiceProvider.
 */
final class PruneInspectorEntriesCommand extends Command
{
    protected $signature = 'tooling:inspector-prune {--days= : Rétention en jours (défaut : tooling.inspector.retention_days)}';

    protected $description = 'Purge les entrées du Backend Inspector plus anciennes que la rétention configurée';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?? config('tooling.inspector.retention_days', 14));

        if ($days < 1) {
            $this->error('La rétention doit être d\'au moins 1 jour.');

            return self::FAILURE;
        }

        $deleted = InspectorEntry::query()->olderThanDays($days)->delete();

        $this->info("{$deleted} entrée(s) de l'Inspector supprimée(s) (plus de {$days} jour(s)).");

        return self::SUCCESS;
    }
}
