<?php

namespace App\Notifications\Auth;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Email de réinitialisation du mot de passe envoyé par la file d'attente : POST /auth/forgot-password
 * ne dépend plus du serveur SMTP. Le lien vers la SPA reste celui défini par
 * ResetPassword::createUrlUsing() dans AppServiceProvider.
 */
class QueuedResetPassword extends ResetPassword implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> secondes entre deux tentatives */
    public array $backoff = [30, 120];

    public function __construct(string $token)
    {
        parent::__construct($token);
        $this->afterCommit();
    }
}
