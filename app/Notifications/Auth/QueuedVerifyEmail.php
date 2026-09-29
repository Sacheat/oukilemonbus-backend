<?php

namespace App\Notifications\Auth;

use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Email de vérification envoyé par la file d'attente : la requête HTTP (inscription, renvoi)
 * ne dépend plus du serveur SMTP. Le lien vers la SPA reste celui défini par
 * VerifyEmail::createUrlUsing() dans AppServiceProvider (signé au moment de l'envoi).
 * En cas d'échec SMTP, le worker réessaie ; l'échec final est journalisé dans failed_jobs.
 */
class QueuedVerifyEmail extends VerifyEmail implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> secondes entre deux tentatives */
    public array $backoff = [30, 120];

    public function __construct()
    {
        $this->afterCommit();
    }
}
