<?php

namespace App\Console\Commands;

use App\Jobs\SendUserDigestJob;
use App\Models\NotificationDigestQueue;
use Illuminate\Console\Command;

/**
 * Déclenche l'envoi des digests de notifications en attente.
 * Appelée par le scheduler (typiquement 1× par jour et 1× par semaine selon les préférences utilisateurs).
 * Chaque utilisateur éligible reçoit un job indépendant pour isoler les erreurs.
 */
class SendNotificationDigestsCommand extends Command
{
    protected $signature = 'notifications:send-digests';

    protected $description = 'Envoie les notifications digest en attente';

    public function handle(): int
    {
        // On récupère les utilisateurs ayant au moins une entrée de digest prête à partir :
        // - non encore envoyée (sent_at est null)
        // - sans délai d'attente (available_at null) ou dont le délai est écoulé (quiet hours)
        $userIds = NotificationDigestQueue::query()
            ->whereNull('sent_at')
            ->where(function ($query): void {
                $query->whereNull('available_at')
                    ->orWhere('available_at', '<=', now());
            })
            ->distinct()
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        foreach ($userIds as $userId) {
            // Un job par utilisateur : si l'un échoue, les autres ne sont pas affectés.
            SendUserDigestJob::dispatch($userId);
        }

        $this->info('Digest jobs dispatched: '.count($userIds));

        return self::SUCCESS;
    }
}
