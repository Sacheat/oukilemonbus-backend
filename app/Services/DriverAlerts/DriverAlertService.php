<?php

namespace App\Services\DriverAlerts;

use App\Enums\DriverAlertStatus;
use App\Enums\TripProgressState;
use App\Mail\DriverAlertOnCallMail;
use App\Models\DriverAlert;
use App\Models\Municipality;
use App\Models\Reservation;
use App\Models\User;
use App\Services\Audit\DomainEventLogger;
use App\Services\Notifications\NotificationDispatchService;
use App\Services\Notifications\NotificationRecipientResolver;
use App\Services\Reservations\TripProgressService;
use App\Support\DevicePosition;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Demandes d'aide des chauffeurs (bouton « Aide » de « Ma journée »).
 *
 * Création (chauffeur, driver-alerts.create) : position du téléphone si elle est envoyée ; sinon dernier lieu connu,
 * déduit de l'avancement du trajet (en route vers la destination ou sur place → destination ; sinon → lieu de départ).
 * Le trajet est facultatif (aide entre deux trajets) ; s'il est donné, ce doit être une mission du chauffeur.
 *
 * Notifications, toujours envoyées (catégorie driver_alert, urgente : ni récapitulatif, ni délai anti-spam, ni
 * préférence qui la coupe) : agents et admins actifs (cloche + email avec « Voir l'alerte » et « Ouvrir la carte »),
 * et le contact de garde de la commune active s'il est renseigné (email seul). À la prise en charge, le chauffeur est
 * prévenu.
 *
 * Suivi (agent / admin, driver-alerts.manage) : ouverte → prise_en_charge → cloturee (note obligatoire).
 */
class DriverAlertService
{
    public function __construct(
        private readonly TripProgressService $tripProgressService,
        private readonly NotificationDispatchService $notificationDispatchService,
        private readonly NotificationRecipientResolver $notificationRecipientResolver,
        private readonly DomainEventLogger $eventLogger
    ) {}

    public function canManage(User $user): bool
    {
        return $user->can('driver-alerts.manage');
    }

    public function ensureCanView(DriverAlert $alert, User $user): void
    {
        if ($this->canManage($user) || $this->isOwnAlert($alert, $user)) {
            return;
        }

        throw new AuthorizationException('Vous n’êtes pas autorisé à voir cette demande d’aide.');
    }

    /**
     * Liste paginée : agent / admin toutes, chauffeur les siennes. Ouvertes et prises en charge d'abord, puis les plus
     * récentes. Filtres : statuses[], date_from / date_to (jour de création, Bruxelles).
     */
    public function list(User $user, array $filters): LengthAwarePaginator
    {
        $timezone = $this->timezone();

        return DriverAlert::query()
            ->when(! $this->canManage($user), fn ($query) => $query->whereHas(
                'driver',
                fn ($driver) => $driver->where('user_id', $user->getKey())
            ))
            ->when($filters['statuses'] ?? null, fn ($query, array $statuses) => $query->whereIn('status', $statuses))
            ->when($filters['date_from'] ?? null, fn ($query, string $day) => $query->where(
                'created_at', '>=', CarbonImmutable::parse($day, $timezone)->startOfDay()->utc()
            ))
            ->when($filters['date_to'] ?? null, fn ($query, string $day) => $query->where(
                'created_at', '<=', CarbonImmutable::parse($day, $timezone)->endOfDay()->utc()
            ))
            ->with($this->relations())
            ->orderByRaw('CASE WHEN status = ? THEN 1 ELSE 0 END', [DriverAlertStatus::CLOTUREE->value]) // clôturées en dernier
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 15));
    }

    public function create(User $actor, ?Reservation $reservation, DevicePosition $position, ?string $message): DriverAlert
    {
        $driver = $actor->driver;

        if (! $actor->can('driver-alerts.create') || $driver === null) {
            throw new AuthorizationException('Seul un chauffeur peut envoyer une demande d’aide.');
        }

        if ($reservation !== null && $reservation->driver_id !== $driver->id) {
            throw ValidationException::withMessages(['reservation_id' => 'Ce trajet ne fait pas partie de vos missions.']);
        }

        $progress = $reservation !== null ? $this->tripProgressService->stateFor($reservation->tripEvents()->get()) : null;
        $fallback = $position->latitude === null && $reservation !== null ? $this->fallbackLocation($reservation, $progress) : null;

        $alert = DB::transaction(function () use ($actor, $driver, $reservation, $position, $message, $progress, $fallback): DriverAlert {
            $alert = DriverAlert::query()->create([
                'driver_id' => $driver->id,
                'reservation_id' => $reservation?->id,
                'vehicle_id' => $reservation?->vehicle_id,
                'status' => DriverAlertStatus::OUVERTE,
                'message' => $message !== null && trim($message) !== '' ? trim($message) : null,
                'trip_progress' => $progress,
                'position_updates' => 0,
                'fallback_label' => $fallback['label'] ?? null,
                'fallback_latitude' => $fallback['latitude'] ?? null,
                'fallback_longitude' => $fallback['longitude'] ?? null,
                'created_by_user_id' => $actor->id,
                ...$position->toColumns(),
            ]);

            $this->eventLogger->log(
                aggregateType: 'driver_alert',
                aggregateId: $alert->id,
                eventType: 'driver_alert.created',
                actor: $actor,
                newValues: ['reservation_id' => $reservation?->id, 'position_state' => $position->state->value]
            );

            return $alert;
        });

        $alert->load($this->relations());
        $this->notifyCreated($alert);

        return $alert;
    }

    /** « Renvoyer ma position » : remplace la position d'une alerte encore ouverte ou prise en charge. */
    public function updatePosition(DriverAlert $alert, User $actor, DevicePosition $position): DriverAlert
    {
        if (! $this->isOwnAlert($alert, $actor)) {
            throw new AuthorizationException('Seul le chauffeur qui a demandé de l’aide peut mettre à jour sa position.');
        }

        $this->ensureOpen($alert);

        $alert->update([
            ...$position->toColumns(),
            'position_updates' => $alert->position_updates + 1,
        ]);

        $this->eventLogger->log(
            aggregateType: 'driver_alert',
            aggregateId: $alert->id,
            eventType: 'driver_alert.position_updated',
            actor: $actor,
            newValues: ['position_state' => $position->state->value]
        );

        return $alert->refresh()->load($this->relations());
    }

    /**
     * Un agent prend en charge une alerte ouverte ; le chauffeur est prévenu.
     * Mise à jour conditionnelle en une requête (WHERE status = ouverte) : si deux agents cliquent en même temps, un
     * seul la modifie ; l'autre reçoit 422 « déjà prise en charge par … » et le chauffeur n'est prévenu qu'une fois.
     */
    public function take(DriverAlert $alert, User $actor): DriverAlert
    {
        $updated = DriverAlert::query()
            ->whereKey($alert->getKey())
            ->where('status', DriverAlertStatus::OUVERTE->value)
            ->update([
                'status' => DriverAlertStatus::PRISE_EN_CHARGE->value,
                'handled_by_user_id' => $actor->id,
                'handled_at' => now(),
                'updated_at' => now(),
            ]);

        if ($updated === 0) {
            throw ValidationException::withMessages(['status' => $this->alreadyHandledMessage($alert->refresh())]);
        }

        $this->eventLogger->log(aggregateType: 'driver_alert', aggregateId: $alert->id, eventType: 'driver_alert.taken', actor: $actor);

        $alert->refresh()->load($this->relations());
        $this->notifyDriverTaken($alert, $actor);

        return $alert;
    }

    /** Clôture (note obligatoire), en une requête conditionnelle comme take() : une seule clôture gagne. */
    public function close(DriverAlert $alert, User $actor, string $note): DriverAlert
    {
        $updated = DriverAlert::query()
            ->whereKey($alert->getKey())
            ->where('status', '!=', DriverAlertStatus::CLOTUREE->value)
            ->update([
                'status' => DriverAlertStatus::CLOTUREE->value,
                'handled_by_user_id' => DB::raw('COALESCE(handled_by_user_id, '.(int) $actor->id.')'),
                'handled_at' => DB::raw('COALESCE(handled_at, \''.now()->toDateTimeString().'\')'),
                'closed_by_user_id' => $actor->id,
                'closed_at' => now(),
                'closing_note' => trim($note),
                'updated_at' => now(),
            ]);

        if ($updated === 0) {
            throw ValidationException::withMessages(['status' => 'Cette demande d’aide est déjà clôturée.']);
        }

        $this->eventLogger->log(
            aggregateType: 'driver_alert',
            aggregateId: $alert->id,
            eventType: 'driver_alert.closed',
            actor: $actor,
            newValues: ['closing_note' => trim($note)]
        );

        return $alert->refresh()->load($this->relations());
    }

    /** @return list<string> */
    public function relations(): array
    {
        return [
            'driver.user',
            'vehicle',
            'reservation.organization',
            'reservation.pickupLocation',
            'reservation.destinationLocation',
            'handledByUser',
            'closedByUser',
        ];
    }

    /** Liens vers une carte publique (OpenStreetMap, Google Maps) du point de l'alerte, null sans point */
    public function mapLinks(DriverAlert $alert): ?array
    {
        $point = $alert->mapPoint();

        if ($point === null) {
            return null;
        }

        $coordinates = $point['latitude'].','.$point['longitude'];

        return [
            'openstreetmap' => sprintf(
                'https://www.openstreetmap.org/?mlat=%1$s&mlon=%2$s#map=17/%1$s/%2$s',
                $point['latitude'],
                $point['longitude']
            ),
            'google' => 'https://www.google.com/maps/search/?api=1&query='.rawurlencode($coordinates),
        ];
    }

    /** Dernier lieu connu, d'après l'avancement : vers la destination ou sur place → destination ; sinon → départ. */
    private function fallbackLocation(Reservation $reservation, ?TripProgressState $progress): ?array
    {
        $reservation->loadMissing(['pickupLocation', 'destinationLocation']);

        [$location, $label] = match ($progress) {
            TripProgressState::EN_ROUTE_ALLER => [$reservation->destinationLocation, 'En route vers la destination'],
            TripProgressState::SUR_PLACE => [$reservation->destinationLocation, 'Sur place, à la destination'],
            TripProgressState::EN_ROUTE_RETOUR => [$reservation->pickupLocation, 'En route vers le lieu de départ (retour)'],
            TripProgressState::TERMINE => [$reservation->pickupLocation, 'Revenu au lieu de départ'],
            default => [$reservation->pickupLocation, 'Au lieu de départ ou en route vers lui'],
        };

        if ($location === null) {
            return null;
        }

        return [
            'label' => $label.' : '.($location->formatted_address ?? 'adresse inconnue'),
            'latitude' => $location->latitude !== null ? (float) $location->latitude : null,
            'longitude' => $location->longitude !== null ? (float) $location->longitude : null,
        ];
    }

    private function notifyCreated(DriverAlert $alert): void
    {
        $driverName = $this->driverName($alert);
        $plate = $alert->vehicle?->licence_plate;
        $title = 'Demande d’aide'.($plate ? " : {$plate}" : '').($driverName ? " ({$driverName})" : '');
        $message = $this->alertSummary($alert);
        $links = $this->mapLinks($alert);

        $this->notificationDispatchService->dispatch(
            users: $this->notificationRecipientResolver->internalUsers(),
            eventKey: 'driver_alert.created',
            category: 'driver_alert',
            title: $title,
            message: $message,
            reservation: $alert->reservation,
            priority: 'high',
            urgent: true,
            link: [
                'path' => "/gestion/alertes/{$alert->id}",
                'label' => 'Voir l’alerte',
                'overrides_reservation' => true,
                'secondary' => $links ? ['url' => $links['openstreetmap'], 'label' => 'Ouvrir la carte'] : null,
            ]
        );

        $municipality = Municipality::query()->where('is_active', true)->first();

        if ($municipality?->on_call_email) {
            try {
                Mail::to($municipality->on_call_email, $municipality->on_call_name)
                    ->queue((new DriverAlertOnCallMail($alert, $title, $message, $links))->onQueue('critical'));
            } catch (Throwable $exception) {
                // Une panne d'envoi ne doit pas empêcher l'alerte d'être enregistrée ni les agents d'être prévenus
                Log::warning('Email au contact de garde non envoyé', ['alert_id' => $alert->id, 'error' => $exception->getMessage()]);
            }
        }
    }

    /**
     * L'email au contact de garde n'est pas parti (DriverAlertOnCallMail::failed) : les agents et admins sont prévenus
     * dans l'application avec le téléphone du contact, et l'échec est noté dans l'historique de l'alerte.
     */
    public function onCallMailFailed(DriverAlert $alert, Throwable $exception): void
    {
        $municipality = Municipality::query()->where('is_active', true)->first();
        $contact = trim(($municipality?->on_call_name ?? 'Le contact de garde').($municipality?->on_call_phone ? " ({$municipality->on_call_phone})" : ''));

        $this->eventLogger->log(
            aggregateType: 'driver_alert',
            aggregateId: $alert->id,
            eventType: 'driver_alert.on_call_failed',
            newValues: ['on_call_email' => $municipality?->on_call_email, 'error' => mb_substr($exception->getMessage(), 0, 255)]
        );

        $this->notificationDispatchService->dispatch(
            users: $this->notificationRecipientResolver->internalUsers(),
            eventKey: 'driver_alert.on_call_failed',
            category: 'driver_alert',
            title: 'Contact de garde non prévenu',
            message: "L’email de la demande d’aide n° {$alert->id} n’a pas pu être envoyé au contact de garde. Prévenez-le autrement : {$contact}.",
            reservation: $alert->reservation,
            priority: 'high',
            urgent: true,
            link: ['path' => "/gestion/alertes/{$alert->id}", 'label' => 'Voir l’alerte', 'overrides_reservation' => true]
        );
    }

    private function notifyDriverTaken(DriverAlert $alert, User $actor): void
    {
        $driverUser = $alert->driver?->user;

        if ($driverUser === null) {
            return;
        }

        $agentName = trim($actor->first_name.' '.$actor->last_name);

        $this->notificationDispatchService->dispatch(
            users: collect([$driverUser]),
            eventKey: 'driver_alert.taken',
            category: 'driver_alert',
            title: 'Votre demande d’aide est prise en charge',
            message: "Demande d’aide n° {$alert->id} : {$agentName} s’en occupe. Restez joignable.",
            reservation: $alert->reservation,
            priority: 'high',
            urgent: true,
            link: ['path' => '/chauffeur/journee', 'label' => 'Ma journée', 'overrides_reservation' => true]
        );
    }

    /** Texte commun à la cloche, à l'email et au contact de garde */
    public function alertSummary(DriverAlert $alert): string
    {
        $timezone = $this->timezone();
        $parts = ["Demande d’aide n° {$alert->id} à ".$alert->created_at->setTimezone($timezone)->format('H:i').'.'];
        $reservation = $alert->reservation;

        if ($reservation !== null) {
            $passengers = (int) $reservation->nb_children + (int) $reservation->nb_adults;
            $parts[] = "Trajet n° {$reservation->id}"
                .($reservation->organization ? " ({$reservation->organization->name})" : '')
                .", {$passengers} passager".($passengers > 1 ? 's' : '').' prévu'.($passengers > 1 ? 's' : '').'.';
        }

        if ($alert->message) {
            $parts[] = "Message : « {$alert->message} »";
        }

        $parts[] = match (true) {
            $alert->latitude !== null => 'Position envoyée'.($alert->accuracy_m ? " (à {$alert->accuracy_m} m près)" : '').'.',
            $alert->fallback_label !== null => 'Position non disponible ('.$alert->position_state->label().'). Dernier lieu connu : '.$alert->fallback_label.'.',
            default => 'Position non disponible ('.$alert->position_state->label().').',
        };

        if ($alert->driver?->user?->phone) {
            $parts[] = 'Chauffeur : '.$alert->driver->user->phone.'.';
        }

        return implode(' ', $parts);
    }

    private function alreadyHandledMessage(DriverAlert $alert): string
    {
        if ($alert->status === DriverAlertStatus::CLOTUREE) {
            return 'Cette demande d’aide est déjà clôturée.';
        }

        $alert->loadMissing('handledByUser');
        $name = $alert->handledByUser ? trim($alert->handledByUser->first_name.' '.$alert->handledByUser->last_name) : null;

        return $name ? "Cette demande d’aide est déjà prise en charge par {$name}." : 'Cette demande d’aide est déjà prise en charge.';
    }

    private function ensureOpen(DriverAlert $alert): void
    {
        if (! $alert->status->isOpen()) {
            throw ValidationException::withMessages(['status' => 'Cette demande d’aide est clôturée.']);
        }
    }

    private function isOwnAlert(DriverAlert $alert, User $user): bool
    {
        return $user->driver !== null && $alert->driver_id === $user->driver->id;
    }

    private function driverName(DriverAlert $alert): ?string
    {
        $user = $alert->driver?->user;

        return $user ? trim($user->first_name.' '.$user->last_name) : null;
    }

    private function timezone(): string
    {
        return config('reservations.local_timezone', 'Europe/Brussels');
    }
}
