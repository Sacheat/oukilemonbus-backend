<?php

namespace App\Support\Mail;

use App\Enums\DocumentType;
use App\Enums\ReservationStatus;
use App\Models\Document;
use App\Models\Reservation;
use App\Models\User;
use App\Services\Locations\MunicipalityService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Logique de présentation des emails de l'application : ton et libellé de la pastille, lien vers la SPA selon le
 * destinataire, objet, textes dédiés des événements clés, formatage. Aucun rendu ici : les gabarits Blade
 * (resources/views/emails) affichent ce que cette classe prépare, ce qui la rend testable sans rendu.
 *
 * Le lien vers la SPA est réservé à l'email : le canal `database` garde son `action_url` (lien signé du document).
 */
final class EmailPresenter
{
    /** Tons des pastilles : fond / texte (charte des emails). */
    private const array TONES = [
        'success' => ['bg' => '#eaf6ef', 'fg' => '#237452'],
        'warning' => ['bg' => '#fff5dc', 'fg' => '#865d16'],
        'danger' => ['bg' => '#fdecea', 'fg' => '#b42318'],
        'info' => ['bg' => '#eaf0ff', 'fg' => '#1d4ed8'],
        'conflict' => ['bg' => '#f1edfa', 'fg' => '#6454a0'],
        'neutral' => ['bg' => '#eef1f5', 'fg' => '#56657a'],
    ];

    /** Libellés précis par événement ; sinon repli par ton (FALLBACK_LABELS). */
    private const array LABELS = [
        'reservation.confirmed' => 'Réservation confirmée',
        'reservation.approved' => 'Réservation approuvée',
        'reservation.rejected' => 'Réservation refusée',
        'reservation.cancelled' => 'Réservation annulée',
        'reservation.passengers_confirmed' => 'Passagers confirmés',
        'reservation.confirmation_reminder' => 'Passagers à confirmer',
        'reservation.conflict_detected' => 'Conflit',
        'reservation.conflict_resolved' => 'Conflit résolu',
        'reservation.expired' => 'Réservation clôturée',
        'reservation.expired.summary' => 'Réservations clôturées',
        'trip_report.not_realised' => 'Compte rendu du chauffeur',
        'driver_alert.created' => 'Demande d’aide',
        'driver_alert.taken' => 'Aide en cours',
        'organization.banned' => 'Organisation suspendue',
        'organization.unbanned' => 'Organisation réactivée',
        'document.deliberation.generated' => 'Nouveau document',
        'planning.export.ready' => 'Export du planning',
        'org_registration_request.created' => 'Demande à traiter',
        'org_registration_request.approved' => 'Demande approuvée',
        'org_registration_request.rejected' => 'Demande refusée',
        'user_org_request.created' => 'Demande à traiter',
        'user_org_request.approved' => 'Demande approuvée',
        'user_org_request.rejected' => 'Demande refusée',
    ];

    private const array FALLBACK_LABELS = [
        'success' => 'Confirmé',
        'warning' => 'À noter',
        'danger' => 'Important',
        'info' => 'Information',
        'conflict' => 'Conflit',
        'neutral' => 'Réservation',
    ];

    /** Libellé du document dans « Ouvrir … », par type. */
    private const array DOCUMENT_LABELS = [
        'deliberation' => 'la décision du Collège (PDF)',
        'feuille_route' => 'la feuille de route (PDF)',
        'planning' => 'le planning',
        'attestation' => "l'attestation (PDF)",
        'justificatif' => 'le justificatif',
    ];

    public function __construct(
        private readonly MunicipalityService $municipalityService,
    ) {}

    public function communeName(): string
    {
        return $this->municipalityService->name();
    }

    public function firstName(?User $user): ?string
    {
        $name = trim((string) $user?->first_name);

        return $name !== '' ? $name : null;
    }

    /** Agents et admins : l'espace de gestion (« Gestion » dans l'en-tête de l'email). */
    public function isInternal(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'agent_communal']);
    }

    /** Chauffeur affecté à cette réservation (et non pas simplement titulaire du rôle chauffeur). */
    public function isAssignedDriver(User $user, ?Reservation $reservation): bool
    {
        return $reservation !== null
            && $user->hasRole('chauffeur')
            && $reservation->driver?->user_id === $user->id;
    }

    /**
     * Ton et libellé de la pastille, d'après la clé d'événement puis la catégorie (même logique que
     * `notificationKind` du front, dans l'ordre).
     *
     * @return array{tone: string, label: string, bg: string, fg: string}
     */
    public function tone(string $eventKey, string $category): array
    {
        $tone = match (true) {
            (bool) preg_match('/(rejected|refused)$/', $eventKey) => 'danger',
            str_ends_with($eventKey, 'cancelled'), $category === 'last_minute_cancellation' => 'danger',
            (bool) preg_match('/(approved|confirmed|accepted)$/', $eventKey) && ! str_contains($eventKey, 'passengers') => 'success',
            str_contains($eventKey, 'passengers'), $eventKey === 'reservation.confirmation_reminder' => 'info',
            str_starts_with($eventKey, 'document.') => 'info',
            str_contains($eventKey, 'conflict') => 'conflict',
            $eventKey === 'trip_report.not_realised' => 'danger',
            $eventKey === 'organization.banned' => 'danger',
            $eventKey === 'organization.unbanned' => 'success',
            $eventKey === 'driver_alert.created' => 'danger',
            $eventKey === 'driver_alert.taken' => 'info',
            $category === 'admin_workflow', $category === 'driver_planning' => 'info',
            default => 'neutral',
        };

        $label = self::LABELS[$eventKey] ?? self::FALLBACK_LABELS[$tone];

        if ($eventKey === 'reservation.confirmed' && $category === 'driver_planning') {
            $label = 'Mission confirmée';
        }

        return ['tone' => $tone, 'label' => $label, ...self::TONES[$tone]];
    }

    /** @return array{bg: string, fg: string} */
    public function toneColors(string $tone): array
    {
        return self::TONES[$tone] ?? self::TONES['neutral'];
    }

    /**
     * Bouton principal de l'email : lien vers la SPA, selon le destinataire (comme `getHomePath` du front : agents et
     * admins `/gestion`, chauffeur `/chauffeur`, sinon `/espace`). `$explicit` (['path', 'label']) est le lien fourni par
     * l'appelant quand il n'y a pas de réservation (demandes d'adhésion et de création). Avec une réservation, c'est
     * son lien qui prime, sauf si `$explicit['overrides_reservation']` est vrai (demande d'aide d'un chauffeur, qui
     * mène à sa propre page). Sans réservation ni lien : null.
     *
     * @param  array{path: string, label: string}|null  $explicit
     * @return array{url: string, label: string}|null
     */
    public function link(User $recipient, ?Reservation $reservation, ?array $explicit = null): ?array
    {
        if ($explicit !== null && ($explicit['overrides_reservation'] ?? false)) {
            return ['url' => $this->spaUrl($explicit['path']), 'label' => $explicit['label']];
        }

        if ($reservation !== null) {
            $target = $this->reservationTarget($recipient, $reservation);

            if ($target !== null) {
                return ['url' => $this->spaUrl($target['path']), 'label' => $target['label']];
            }
        }

        return $explicit === null ? null : ['url' => $this->spaUrl($explicit['path']), 'label' => $explicit['label']];
    }

    /** @return array{path: string, label: string}|null */
    private function reservationTarget(User $recipient, Reservation $reservation): ?array
    {
        if ($this->isInternal($recipient)) {
            return ['path' => "/gestion/reservations?reservation={$reservation->id}", 'label' => 'Ouvrir la réservation'];
        }

        if ($this->isAssignedDriver($recipient, $reservation)) {
            return ['path' => "/chauffeur/missions/{$reservation->id}", 'label' => 'Voir la mission'];
        }

        // Un chauffeur qui n'est pas celui du trajet n'a pas de page pour ce trajet, sauf s'il en est le demandeur.
        if ($recipient->hasRole('chauffeur') && $reservation->requested_by_user_id !== $recipient->id) {
            return null;
        }

        return ['path' => "/espace/deplacements/{$reservation->id}", 'label' => 'Voir le déplacement'];
    }

    public function spaUrl(string $path): string
    {
        return rtrim((string) config('app.frontend_url'), '/').$path;
    }

    /**
     * Lien secondaire vers le document joint à la notification, sous le bouton principal.
     *
     * @return array{url: string, label: string, attached: bool}|null
     */
    public function documentLink(?Document $document, ?string $actionUrl, bool $attached): ?array
    {
        if ($document === null || $actionUrl === null) {
            return null;
        }

        $type = $document->document_type instanceof DocumentType ? $document->document_type->value : (string) $document->document_type;

        return [
            'url' => $actionUrl,
            'label' => 'Ouvrir '.(self::DOCUMENT_LABELS[$type] ?? 'le document'),
            'attached' => $attached,
        ];
    }

    /**
     * Texte du message pour l'email : le motif (affiché à part, en citation) en est retiré, et « #104 » devient
     * « n° 104 ». Les messages stockés (canal database) ne changent pas.
     */
    public function bodyMessage(string $message, ?string $reason = null): string
    {
        if ($reason !== null && $reason !== '') {
            $message = (string) preg_replace('/\s*Motif\s*:\s*'.preg_quote($reason, '/').'\.?\s*$/u', '', $message);
        }

        return $this->humanize($message);
    }

    /** « #104 » → « n° 104 » (textes destinés aux personnes). */
    public function humanize(string $text): string
    {
        return (string) preg_replace('/#(\d+)/', 'n° $1', $text);
    }

    /**
     * Objet de l'email : plus précis que le titre pour la confirmation, le refus, l'annulation et le trajet non
     * réalisé ; sinon le titre. Sans réservation (supprimée depuis), le titre.
     */
    public function subject(string $eventKey, string $title, ?Reservation $reservation, User $recipient): string
    {
        if ($reservation === null) {
            return $this->humanize($title);
        }

        $number = $reservation->id;
        $driver = $this->isAssignedDriver($recipient, $reservation);

        return match (true) {
            $this->isConfirmation($eventKey, $title, $reservation) && ! $driver => 'Votre déplacement du '.$this->shortDate($reservation).' est confirmé',
            $eventKey === 'reservation.rejected' => "Votre réservation n° {$number} est refusée",
            $eventKey === 'reservation.cancelled' && $driver => "Réservation n° {$number} annulée",
            $eventKey === 'reservation.cancelled' => "Votre réservation n° {$number} est annulée",
            $eventKey === 'trip_report.not_realised' => "Trajet non réalisé : réservation n° {$number}",
            default => $this->humanize($title),
        };
    }

    /**
     * Titre (h1) et phrase d'introduction de l'email : textes dédiés pour la confirmation, le refus et l'annulation,
     * sinon le titre et le message de la notification.
     *
     * @return array{title: string, message: string}
     */
    public function copy(string $eventKey, string $title, string $message, ?string $reason, ?Reservation $reservation, User $recipient): array
    {
        $default = ['title' => $this->humanize($title), 'message' => $this->bodyMessage($message, $reason)];

        if ($reservation === null) {
            return $default;
        }

        $number = $reservation->id;
        $driver = $this->isAssignedDriver($recipient, $reservation);

        return match (true) {
            $this->isConfirmation($eventKey, $title, $reservation) && ! $driver => [
                'title' => 'Votre déplacement est confirmé',
                'message' => 'Le bus communal vous attendra le jour prévu. Un chauffeur et un véhicule sont affectés à votre déplacement.',
            ],
            $eventKey === 'reservation.rejected' => [
                'title' => 'Votre réservation est refusée',
                'message' => "Votre demande de réservation n° {$number} n'a pas pu être acceptée.",
            ],
            $eventKey === 'reservation.cancelled' && $driver => [
                'title' => 'Réservation annulée',
                'message' => "La réservation n° {$number} a été annulée : vous n'avez plus à assurer cette mission.",
            ],
            $eventKey === 'reservation.cancelled' => [
                'title' => 'Votre réservation est annulée',
                'message' => "La réservation n° {$number} a été annulée.",
            ],
            default => $default,
        };
    }

    /**
     * Données du récapitulatif : objet, surtitre, titre, introduction et une ligne par notification (priorité haute en
     * tête, ordre d'arrivée sinon). Heures de Bruxelles.
     *
     * @param  list<array{title: string, message: string, category: string, priority: string, event_key: string, reservation_id: ?int, link: ?array, created_at: ?string}>  $items
     * @return array{subject: string, surtitle: string, heading: string, intro: string, items: list<array>, button: array{url: string, label: string}}
     */
    public function digest(User $user, array $items, string $frequency, ?CarbonInterface $since = null, ?CarbonInterface $now = null): array
    {
        $timezone = (string) config('reservations.local_timezone', 'Europe/Brussels');
        $now = ($now ?? CarbonImmutable::now())->setTimezone($timezone)->locale('fr');
        $count = count($items);
        $countText = $count === 1 ? '1 notification' : "{$count} notifications";

        $reservations = Reservation::query()
            ->with('driver')
            ->whereIn('id', array_filter(array_column($items, 'reservation_id')))
            ->get()
            ->keyBy('id');

        // Tri stable : priorité haute en tête, puis ordre d'arrivée.
        $ordered = collect($items)->values()->map(fn (array $item, int $index): array => [...$item, '_index' => $index])
            ->sortBy([fn (array $a, array $b): int => (int) ($b['priority'] === 'high') <=> (int) ($a['priority'] === 'high'), ['_index', 'asc']])
            ->values();

        $rows = $ordered->map(function (array $item) use ($user, $reservations, $timezone): array {
            $reservation = ($item['reservation_id'] ?? null) !== null ? $reservations->get($item['reservation_id']) : null;
            $time = ($item['created_at'] ?? null) !== null
                ? CarbonImmutable::parse($item['created_at'])->setTimezone($timezone)->format('H:i')
                : null;

            return [
                'dot' => $this->tone((string) $item['event_key'], (string) $item['category'])['fg'],
                'title' => $this->humanize((string) $item['title']),
                'time' => $time,
                'message' => $this->humanize((string) $item['message']),
                'link' => $this->link($user, $reservation, $item['link'] ?? null),
            ];
        })->all();

        $firstName = $this->firstName($user);
        $hello = $firstName !== null ? "Bonjour {$firstName}," : 'Bonjour,';
        $sinceLabel = $since !== null ? $this->sinceLabel($since, $now, $timezone) : null;

        return [
            'subject' => $frequency === 'hourly'
                ? "Votre résumé de {$now->format('H')}:00 : {$countText}"
                : "Votre résumé du {$now->translatedFormat('j F')} : {$countText}",
            'surtitle' => 'Résumé du '.$now->translatedFormat('l j F'),
            'heading' => $count === 1 ? '1 nouvelle notification' : "{$count} nouvelles notifications",
            'intro' => $sinceLabel !== null
                ? "{$hello} voici ce qui s’est passé depuis votre dernier résumé ({$sinceLabel})."
                : "{$hello} voici ce qui s’est passé depuis votre dernier résumé.",
            'items' => $rows,
            'button' => ['url' => $this->spaUrl('/notifications'), 'label' => 'Voir toutes mes notifications'],
        ];
    }

    /** « aujourd'hui, 08:00 », « hier, 08:00 » ou « le 1 octobre, 08:00 ». */
    private function sinceLabel(CarbonInterface $since, CarbonImmutable $now, string $timezone): string
    {
        $since = CarbonImmutable::instance($since)->setTimezone($timezone)->locale('fr');
        $day = match ($since->toDateString()) {
            $now->toDateString() => 'aujourd’hui',
            $now->subDay()->toDateString() => 'hier',
            default => 'le '.$since->translatedFormat('j F'),
        };

        return "{$day}, {$since->format('H:i')}";
    }

    /**
     * Confirmation d'un déplacement vue par son demandeur : événement « confirmé », ou « approuvé » et déjà confirmé.
     * Seulement pour les titres standard : « Réservation de nouveau confirmée » (après un conflit) garde son propre texte.
     */
    private function isConfirmation(string $eventKey, string $title, Reservation $reservation): bool
    {
        if (! in_array($title, ['Réservation confirmée', 'Réservation approuvée'], true)) {
            return false;
        }

        return match ($eventKey) {
            'reservation.confirmed' => true,
            'reservation.approved' => $reservation->status === ReservationStatus::CONFIRME,
            default => false,
        };
    }

    /** « 15 octobre » */
    private function shortDate(Reservation $reservation): string
    {
        return $reservation->trip_date->locale('fr')->translatedFormat('j F');
    }
}
