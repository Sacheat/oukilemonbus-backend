<?php

namespace App\Notifications\Auth;

use App\Support\Mail\EmailPresenter;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Email de vérification envoyé par la file d'attente : la requête HTTP (inscription, renvoi)
 * ne dépend plus du serveur SMTP. Le lien vers la SPA reste celui défini par
 * VerifyEmail::createUrlUsing() dans AppServiceProvider (signé au moment de l'envoi).
 * En cas d'échec SMTP, le worker réessaie ; l'échec final est journalisé dans failed_jobs.
 *
 * Gabarit Blade `emails.auth.verify-email` (et sa version texte). `$createdByAdmin` : compte créé par un admin
 * (POST /users), la phrase d'accueil l'annonce au lieu de « Bienvenue ».
 */
class QueuedVerifyEmail extends VerifyEmail implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> secondes entre deux tentatives */
    public array $backoff = [30, 120];

    public function __construct(private readonly bool $createdByAdmin = false)
    {
        $this->afterCommit();
    }

    public function toMail($notifiable)
    {
        $url = $this->verificationUrl($notifiable);

        if (static::$toMailCallback) {
            return call_user_func(static::$toMailCallback, $notifiable, $url);
        }

        return $this->buildMailMessage($url, $notifiable);
    }

    protected function buildMailMessage($url, $notifiable = null)
    {
        $presenter = app(EmailPresenter::class);
        $commune = $presenter->communeName();
        $firstName = $presenter->firstName($notifiable);
        $subject = 'Confirmez votre adresse email';

        return (new MailMessage)
            ->subject($subject)
            ->action('Confirmer mon adresse', $url) // lisible par les tests ; le bouton affiché est celui du gabarit
            ->view(['emails.auth.verify-email', 'emails.auth.verify-email-text'], [
                'pageTitle' => $subject,
                'commune' => $commune,
                'greeting' => $firstName !== null ? "Bonjour {$firstName}," : 'Bonjour,',
                'welcome' => $this->createdByAdmin
                    ? "Un compte a été créé pour vous sur OuKiLéMonBus, le service de réservation du bus communal de {$commune}. Confirmez votre adresse pour l’activer et réserver vos premiers déplacements."
                    : "Bienvenue sur OuKiLéMonBus, le service de réservation du bus communal de {$commune}. Confirmez votre adresse pour réserver vos premiers déplacements.",
                'url' => $url,
                'minutes' => (int) config('auth.verification.expire', 60),
            ]);
    }
}
