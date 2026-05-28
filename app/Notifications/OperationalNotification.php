<?php

namespace App\Notifications;

use App\Models\Document;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class OperationalNotification extends Notification implements ShouldQueue
{
    use Queueable;

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

