<?php

namespace App\Mail;

use App\Models\DriverAlert;
use App\Support\Mail\EmailPresenter;
use App\Support\Mail\TripTicket;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Demande d'aide d'un chauffeur, envoyée au contact de garde de la commune (qui n'a pas forcément de compte) :
 * même gabarit que les notifications, avec le lien direct vers la carte en bouton principal et la page de l'alerte
 * (connexion requise) en second.
 */
class DriverAlertOnCallMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    /** @param  array{openstreetmap: string, google: string}|null  $mapLinks */
    public function __construct(
        public DriverAlert $alert,
        public string $heading,
        public string $body,
        public ?array $mapLinks
    ) {}

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
