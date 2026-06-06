<?php

namespace App\Notifications;

use App\Models\Document;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Notification opérationnelle unitaire envoyée via le pipeline NotificationDispatchService.
 *
 * Canaux supportés : 'database' (synchrone via sendNow) et/ou 'mail' (mis en queue).
 * Le canal effectif est déterminé à l'extérieur et passé dans $channels au constructeur.
 *
 * Pièce jointe mail : le document est attaché uniquement si $attachDocumentIfSmall est vrai,
 * que le fichier existe sur le disque et que sa taille ne dépasse pas
 * notifications.mail.max_attachment_bytes (défaut 5 Mo). Au-delà, seul un lien actionUrl est fourni.
 *
 * Retry : 3 tentatives avec backoff exponentiel (30 s / 120 s / 600 s).
 * L'UUID du job est forcé dès le constructeur pour garantir l'unicité de l'entrée 'database'.
 */
class OperationalNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;


    public bool $failOnTimeout = true;

    public function __construct(
        private readonly string $eventKey,
        private readonly string $category,
        private readonly string $title,
        private readonly string $message,
        private readonly string $priority = 'normal',
        private readonly ?int $reservationId = null,
        private readonly ?string $actionUrl = null,
        private readonly ?Document $document = null,
        private readonly bool $attachDocumentIfSmall = false,
        private readonly array $channels = ['database']
    ) {
        $this->id = (string) Str::uuid();
    }

    public function via(object $notifiable): array
    {
        return $this->channels;
    }

    public function backoff(): array
    {
        return [30, 120, 600];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'event_key' => $this->eventKey,
            'category' => $this->category,
            'priority' => $this->priority,
            'title' => $this->title,
            'message' => $this->message,
            'reservation_id' => $this->reservationId,
            'document_id' => $this->document?->id,
            'action_url' => $this->actionUrl,
            'created_at' => now()->toISOString(),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage())
            ->subject($this->title)
            ->line($this->message);

        if ($this->actionUrl !== null) {
            $mail->action('Ouvrir le document', $this->actionUrl);
        }

        if (
            $this->attachDocumentIfSmall
            && $this->document !== null
            && $this->document->is_available
            && $this->document->file_size <= config('notifications.mail.max_attachment_bytes', 5 * 1024 * 1024)
            && Storage::disk('local')->exists($this->document->file_path)
        ) {
            $mail->attach(Storage::disk('local')->path($this->document->file_path), [
                'as' => $this->document->file_name,
                'mime' => $this->document->mime_type,
            ]);
        }

        return $mail;
    }
}
