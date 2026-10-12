<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Documents\PassengerListService;
use Illuminate\Console\Command;

/**
 * Supprime les listes des passagers dont la conservation est échue (3 ans après l'année du trajet par défaut,
 * config uploads.passenger_lists.retention_years). Planifiée chaque nuit (routes/console.php).
 */
class PurgePassengerListsCommand extends Command
{
    protected $signature = 'documents:purge-passenger-lists';

    protected $description = 'Supprime les listes des passagers dont la durée de conservation est échue';

    public function handle(PassengerListService $passengerLists): int
    {
        $deleted = $passengerLists->purgeExpired();
        $this->info("{$deleted} liste(s) des passagers supprimée(s).");

        return self::SUCCESS;
    }
}
