<?php

namespace App\Console\Commands;

use App\Models\WeeklyPlanningDispatch;
use App\Services\Planning\WeeklyPlanningService;
use Illuminate\Console\Command;

/**
 * Planning hebdomadaire (cahier des charges §5.9) : envoie le planning de la semaine suivante au moment réglé par la
 * commune (vendredi 12:00 par défaut), puis ses versions corrigées. Planifiée toutes les 15 minutes
 * (routes/console.php) : sans rien à envoyer, elle ne fait rien. Règles : WeeklyPlanningService.
 */
class SendWeeklyPlanningCommand extends Command
{
    protected $signature = 'planning:send-weekly';

    protected $description = 'Envoie le planning hebdomadaire et ses versions corrigées aux agents et aux admins.';

    public function handle(WeeklyPlanningService $weeklyPlanningService): int
    {
        $sent = $weeklyPlanningService->run();
        $this->info("{$sent->count()} planning(s) envoyé(s).");
        $sent->each(fn (WeeklyPlanningDispatch $dispatch) => $this->line(
            "  semaine du {$dispatch->week_start->toDateString()}, version {$dispatch->version} : {$dispatch->trip_count} trajet(s), {$dispatch->recipient_count} destinataire(s)"
        ));

        return self::SUCCESS;
    }
}
