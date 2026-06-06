<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Notification de digest regroupant plusieurs notifications en un seul e-mail.
 *
 * Envoyée uniquement par mail (pas de canal 'database') après expiration de la
 * fenêtre de quiet hours. Chaque item du digest est affiché sur une ligne
 * au format "- titre : message".
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

    public function __construct(
        private readonly string $subject,
        private readonly array $items
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
        $mail = (new MailMessage())
            ->subject($this->subject)
            ->line('Voici un resume de vos notifications:');

        foreach ($this->items as $item) {
            $mail->line('- '.$item['title'].' : '.$item['message']);
        }

        return $mail;
    }
}
