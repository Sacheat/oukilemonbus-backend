<?php

namespace App\Notifications;

use App\Models\Document;
use App\Models\Reservation;
use App\Models\User;
use App\Support\Mail\EmailPresenter;
use App\Support\Mail\TripTicket;
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
 * Email : gabarit Blade `emails.notification` (et sa version texte), préparé par EmailPresenter : pastille, bouton vers la SPA
 * selon le destinataire, billet du trajet, motif en citation. `$reason` et `$link` ne servent qu'à l'email : le canal
 * `database` (`toArray`) ne change pas, `message` y garde le motif et `action_url` le lien signé du document.
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
        private readonly array $channels = ['database'],
        private readonly ?string $reason = null,
        private readonly ?array $link = null
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
        /** @var User $notifiable */
        $presenter = app(EmailPresenter::class);
        $reservation = $this->reservation();
        $internal = $presenter->isInternal($notifiable);
        $attached = $this->canAttachDocument();

        $copy = $presenter->copy($this->eventKey, $this->title, $this->message, $this->reason, $reservation, $notifiable);
        $subject = $presenter->subject($this->eventKey, $this->title, $reservation, $notifiable);
        $button = $presenter->link($notifiable, $reservation, $this->link);
        // Lien secondaire fourni par l'appelant (ex. « Ouvrir la carte » d'une demande d'aide), sous le bouton principal
        $secondary = $this->link['secondary'] ?? null;
        $documentLink = $secondary !== null ? ['url' => $secondary['url'], 'label' => $secondary['label'], 'attached' => false] : null;

        if ($this->actionUrl !== null && $this->document !== null) {
            if ($button === null) {
                // Ni réservation ni lien vers la SPA : le document devient l'action principale.
                $button = ['url' => $this->actionUrl, 'label' => 'Ouvrir le document'];
            } else {
                $documentLink = $presenter->documentLink($this->document, $this->actionUrl, $attached);
            }
        }

        $firstName = $presenter->firstName($notifiable);

        $mail = (new MailMessage())
            ->subject($subject)
            ->view(['emails.notification', 'emails.notification-text'], [
                'pageTitle' => $subject,
                'space' => $internal ? 'Gestion' : null,
                'commune' => $presenter->communeName(),
                'priorityHigh' => $this->priority === 'high',
                'tone' => $presenter->tone($this->eventKey, $this->category),
                'heading' => $copy['title'],
                'greeting' => $firstName !== null ? "Bonjour {$firstName}," : 'Bonjour,',
                'body' => $copy['message'],
                'reason' => $this->reason !== null && $this->reason !== '' ? $this->reason : null,
                'ticket' => $reservation !== null ? TripTicket::for($reservation) : null,
                'ticketFull' => ! $internal,
                'button' => $button,
                'documentLink' => $documentLink,
            ]);

        if ($button !== null) {
            $mail->action($button['label'], $button['url']); // lisible par les tests ; le bouton affiché est celui du gabarit
        }

        if ($attached) {
            $mail->attach(Storage::disk('local')->path($this->document->file_path), [
                'as' => $this->document->file_name,
                'mime' => $this->document->mime_type,
            ]);
        }

        return $mail;
    }

    /** Réservation de la notification, chargée au moment de l'envoi (job de la file) ; null si elle n'existe plus. */
    private function reservation(): ?Reservation
    {
        if ($this->reservationId === null) {
            return null;
        }

        return Reservation::query()
            ->with(['pickupLocation', 'destinationLocation', 'vehicle', 'driver.user', 'organization'])
            ->find($this->reservationId);
    }

    private function canAttachDocument(): bool
    {
        return $this->attachDocumentIfSmall
            && $this->document !== null
            && $this->document->is_available
            && $this->document->file_size <= config('notifications.mail.max_attachment_bytes', 5 * 1024 * 1024)
            && Storage::disk('local')->exists($this->document->file_path);
    }
}
