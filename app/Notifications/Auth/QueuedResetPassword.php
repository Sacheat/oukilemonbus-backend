<?php

namespace App\Notifications\Auth;

use App\Support\Mail\EmailPresenter;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Email de réinitialisation du mot de passe envoyé par la file d'attente : POST /auth/forgot-password
 * ne dépend plus du serveur SMTP. Le lien vers la SPA reste celui défini par
 * ResetPassword::createUrlUsing() dans AppServiceProvider.
 *
 * Gabarit Blade `emails.auth.reset-password` (et sa version texte). `$sentByAdmin` : lien envoyé par un admin
 * (POST /users/{user}/password-reset-link), l'introduction le dit.
 */
class QueuedResetPassword extends ResetPassword implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> secondes entre deux tentatives */
    public array $backoff = [30, 120];

    public function __construct(string $token, private readonly bool $sentByAdmin = false)
    {
        parent::__construct($token);
        $this->afterCommit();
    }

    public function toMail($notifiable)
    {
        if (static::$toMailCallback) {
            return call_user_func(static::$toMailCallback, $notifiable, $this->token);
        }

        return $this->buildMailMessage($this->resetUrl($notifiable), $notifiable);
    }

    protected function buildMailMessage($url, $notifiable = null)
    {
        $presenter = app(EmailPresenter::class);
        $firstName = $presenter->firstName($notifiable);
        $subject = 'Réinitialisez votre mot de passe';

        return (new MailMessage)
            ->subject($subject)
            ->action('Choisir un nouveau mot de passe', $url) // lisible par les tests ; le bouton affiché est celui du gabarit
            ->view(['emails.auth.reset-password', 'emails.auth.reset-password-text'], [
                'pageTitle' => $subject,
                'commune' => $presenter->communeName(),
                'greeting' => $firstName !== null ? "Bonjour {$firstName}," : 'Bonjour,',
                'intro' => $this->sentByAdmin
                    ? 'Un administrateur vous a envoyé ce lien pour choisir un nouveau mot de passe pour votre compte OuKiLéMonBus.'
                    : 'Vous avez demandé à changer le mot de passe de votre compte OuKiLéMonBus.',
                'url' => $url,
                'minutes' => (int) config('auth.passwords.'.config('auth.defaults.passwords').'.expire', 60),
            ]);
    }
}
