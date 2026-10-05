<?php

namespace App\Notifications;

use App\Models\User;
use App\Support\Mail\EmailPresenter;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Notification de digest regroupant plusieurs notifications en un seul e-mail.
 *
 * Envoyée uniquement par mail (pas de canal 'database') après expiration de la
 * fenêtre de quiet hours. Gabarit Blade `emails.digest` (et sa version texte) : une ligne par notification
 * (pastille du ton, titre, heure, message, lien vers la SPA), priorité haute en tête, bouton vers /notifications.
 * Les lignes viennent de SendUserDigestJob (title, message, category, priority, event_key, reservation_id, link,
 * created_at) ; la mise en forme est dans EmailPresenter::digest().
 *
 * Retry : 3 tentatives avec backoff (60 s / 300 s / 900 s) — délais plus longs
 * qu'OperationalNotification car le digest agrège plusieurs notifications importantes.
 */
class OperationalDigestNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public bool $failOnTimeout = true;

    /**
     * @param  list<array<string, mixed>>  $items
     * @param  string  $frequency  fréquence de digest du destinataire (hourly, daily, none)
     * @param  string|null  $since  ISO 8601 : envoi du dernier récapitulatif (repère de l'introduction)
     */
    public function __construct(
        private readonly array $items,
        private readonly string $frequency = 'daily',
        private readonly ?string $since = null
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function toMail(object $notifiable): MailMessage
    {
        /** @var User $notifiable */
        $presenter = app(EmailPresenter::class);
        $digest = $presenter->digest(
            $notifiable,
            $this->items,
            $this->frequency,
            $this->since !== null ? CarbonImmutable::parse($this->since) : null
        );

        return (new MailMessage())
            ->subject($digest['subject'])
            ->action($digest['button']['label'], $digest['button']['url'])
            ->view(['emails.digest', 'emails.digest-text'], [
                'pageTitle' => $digest['subject'],
                'commune' => $presenter->communeName(),
                'surtitle' => $digest['surtitle'],
                'heading' => $digest['heading'],
                'intro' => $digest['intro'],
                'items' => $digest['items'],
                'button' => $digest['button'],
            ]);
    }
}
