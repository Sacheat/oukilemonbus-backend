<?php

namespace App\Mail;

use App\Models\DriverAlert;
use App\Services\DriverAlerts\DriverAlertService;
use App\Support\Mail\EmailPresenter;
use App\Support\Mail\TripTicket;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Demande d'aide d'un chauffeur, envoyée au contact de garde de la commune (qui n'a pas forcément de compte) :
 * même gabarit que les notifications, avec le lien direct vers la carte en bouton principal et la page de l'alerte
 * (connexion requise) en second.
 *
 * 3 tentatives (30 s, 2 min, 10 min). Si l'envoi échoue définitivement, failed() prévient les agents et admins dans
 * l'application (« le contact de garde n'a pas pu être prévenu : appelez-le ») et le note dans l'historique de l'alerte :
 * l'échec ne reste pas caché dans la file des jobs en échec.
 */
class DriverAlertOnCallMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /** @param  array{openstreetmap: string, google: string}|null  $mapLinks */
    public function __construct(
        public DriverAlert $alert,
        public string $heading,
        public string $body,
        public ?array $mapLinks
    ) {}

    /** @return list<int> secondes entre les tentatives */
    public function backoff(): array
    {
        return [30, 120, 600];
    }

    /** Envoi définitivement échoué (appelé par le worker après la dernière tentative) */
    public function failed(Throwable $exception): void
    {
        app(DriverAlertService::class)->onCallMailFailed($this->alert, $exception);
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->heading);
    }

    public function content(): Content
    {
        $presenter = app(EmailPresenter::class);
        $reservation = $this->alert->reservation;
        $appLink = ['url' => $presenter->spaUrl("/gestion/alertes/{$this->alert->id}"), 'label' => 'Voir l’alerte dans l’application', 'attached' => false];

        return new Content(
            view: 'emails.notification',
            text: 'emails.notification-text',
            with: [
                'pageTitle' => $this->heading,
                'space' => 'Gestion',
                'commune' => $presenter->communeName(),
                'priorityHigh' => true,
                'tone' => $presenter->tone('driver_alert.created', 'driver_alert'),
                'heading' => $this->heading,
                'greeting' => 'Bonjour,',
                'body' => $this->body,
                'reason' => null,
                'ticket' => $reservation !== null ? TripTicket::for($reservation) : null,
                'ticketFull' => false,
                'button' => $this->mapLinks ? ['url' => $this->mapLinks['openstreetmap'], 'label' => 'Ouvrir la carte'] : ['url' => $appLink['url'], 'label' => $appLink['label']],
                'documentLink' => $this->mapLinks ? $appLink : null,
            ]
        );
    }
}
