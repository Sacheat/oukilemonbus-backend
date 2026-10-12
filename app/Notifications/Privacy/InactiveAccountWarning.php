<?php

namespace App\Notifications\Privacy;

use App\Support\Mail\EmailPresenter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;

/**
 * RGPD, comptes inactifs : prévient la personne avant l'anonymisation automatique de son compte (privacy:prune).
 * Se connecter avant `$anonymizedOn` suffit à garder le compte. Gabarit `emails.privacy.inactive-account`.
 */
class InactiveAccountWarning extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> secondes entre deux tentatives */
    public array $backoff = [30, 120];

    public function __construct(public readonly Carbon $anonymizedOn) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $presenter = app(EmailPresenter::class);
        $commune = $presenter->communeName();
        $firstName = $presenter->firstName($notifiable);
        $subject = 'Votre compte OuKiLéMonBus va être supprimé';
        $url = $presenter->spaUrl('/');
        $date = $this->anonymizedOn->copy()
            ->setTimezone(config('reservations.local_timezone', 'Europe/Brussels'))
            ->locale('fr')
            ->isoFormat('D MMMM YYYY');

        return (new MailMessage)
            ->subject($subject)
            ->action('Me connecter', $url)
            ->view(['emails.privacy.inactive-account', 'emails.privacy.inactive-account-text'], [
                'pageTitle' => $subject,
                'commune' => $commune,
                'greeting' => $firstName !== null ? "Bonjour {$firstName}," : 'Bonjour,',
                'years' => intdiv((int) config('privacy.retention.inactive_accounts_days', 1095), 365),
                'date' => $date,
                'url' => $url,
            ]);
    }
}
