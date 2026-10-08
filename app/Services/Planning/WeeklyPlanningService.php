<?php

namespace App\Services\Planning;

use App\Enums\DocumentType;
use App\Enums\ReservationStatus;
use App\Models\Municipality;
use App\Models\Reservation;
use App\Models\User;
use App\Models\WeeklyPlanningDispatch;
use App\Services\Audit\DomainEventLogger;
use App\Services\Documents\DocumentService;
use App\Services\Notifications\NotificationDispatchService;
use App\Services\Notifications\NotificationPreferenceService;
use App\Services\Notifications\NotificationRecipientResolver;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Planning hebdomadaire envoyé automatiquement (cahier des charges §5.9). Lancé toutes les 15 minutes par
 * `planning:send-weekly` (routes/console.php) :
 *
 *   envoi désactivé par la commune                                  → rien
 *   le moment de l'envoi de cette semaine est passé (vendredi 12:00
 *   par défaut) et le planning de la semaine suivante n'est pas parti → version 1
 *   une semaine déjà envoyée, pas encore terminée (jusqu'au dimanche) :
 *     trajets changés depuis la dernière version, et dernière version
 *     partie il y a plus de update_min_interval_minutes (60)          → version corrigée (2, 3…)
 *
 * Jamais deux fois la même version : la ligne weekly_planning_dispatches (unique semaine + version) est réservée
 * avant de générer le fichier. Si le serveur était arrêté à l'heure prévue, le planning part au passage suivant.
 *
 * Le fichier est l'export Excel du Planning (approuvés et confirmés, colonne Statut), enregistré une seule fois dans
 * Documents ; chaque destinataire reçoit une notification avec un lien personnel vers ce document.
 *
 * Destinataires (agents et admins actifs), selon leur préférence `weekly_planning_opt_in` :
 *   true  → toujours ; false → jamais ;
 *   null  → automatique : le responsable du planning de la commune s'il est désigné (et actif), sinon tous.
 */
class WeeklyPlanningService
{
    public function __construct(
        private readonly PlanningService $planningService,
        private readonly PlanningExportService $planningExportService,
        private readonly DocumentService $documentService,
        private readonly NotificationDispatchService $notificationDispatchService,
        private readonly NotificationRecipientResolver $notificationRecipientResolver,
        private readonly NotificationPreferenceService $notificationPreferenceService,
        private readonly DomainEventLogger $eventLogger
    ) {}

    /** @return Collection<int, WeeklyPlanningDispatch> envois faits pendant ce passage */
    public function run(?CarbonImmutable $now = null): Collection
    {
        $settings = $this->settings();
        $now = ($now ?? CarbonImmutable::now())->setTimezone($this->timezone());
        $sent = collect();

        if (! $settings['enabled']) {
            return $sent;
        }

        // 1. Envoi initial du planning de la semaine suivante
        $thisMonday = $now->startOfWeek(CarbonImmutable::MONDAY)->startOfDay();
        $slot = $this->sendMoment($thisMonday, $settings);
        $nextMonday = $thisMonday->addWeek();

        if ($now->greaterThanOrEqualTo($slot) && ! $this->hasDispatch($nextMonday)) {
            $dispatch = $this->send($nextMonday, 1);
            if ($dispatch !== null) {
                $sent->push($dispatch);
            }
        }

        // 2. Versions corrigées des semaines déjà envoyées et pas encore terminées
        $interval = max(1, (int) config('weekly_planning.update_min_interval_minutes', 60));
        $openWeeks = WeeklyPlanningDispatch::query()
            ->where('week_start', '>=', $thisMonday->toDateString())
            ->selectRaw('week_start, MAX(version) as last_version')
            ->groupBy('week_start')
            ->get();

        foreach ($openWeeks as $week) {
            $weekStart = CarbonImmutable::parse($week->week_start, $this->timezone());
            $last = WeeklyPlanningDispatch::query()
                ->whereDate('week_start', $weekStart->toDateString())
                ->where('version', $week->last_version)
                ->first();

            if ($last === null || $last->sent_at->greaterThan($now->subMinutes($interval))) {
                continue;
            }

            if ($this->signature($this->trips($weekStart)) === $last->signature) {
                continue;
            }

            $dispatch = $this->send($weekStart, $last->version + 1);
            if ($dispatch !== null) {
                $sent->push($dispatch);
            }
        }

        return $sent;
    }

    /**
     * Réglages de la commune active, sinon config/weekly_planning.php.
     *
     * @return array{enabled: bool, send_day: int, send_time: string, owner: ?User}
     */
    public function settings(?Municipality $municipality = null): array
    {
        $municipality ??= Municipality::query()->where('is_active', true)->orderBy('id')->first();
        $day = (int) ($municipality?->weekly_planning_send_day ?? config('weekly_planning.send_day', 5));
        $time = (string) ($municipality?->weekly_planning_send_time ?? config('weekly_planning.send_time', '12:00'));

        return [
            'enabled' => (bool) ($municipality?->weekly_planning_enabled ?? config('weekly_planning.enabled', true)),
            'send_day' => min(7, max(1, $day)),
            'send_time' => preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time) === 1 ? $time : '12:00',
            'owner' => $this->effectiveOwner($municipality),
        ];
    }

    /**
     * Le responsable désigné compte seulement s'il est encore actif et agent ou admin ; sinon personne (= tous).
     */
    public function effectiveOwner(?Municipality $municipality): ?User
    {
        $owner = $municipality?->weekly_planning_owner_user_id
            ? User::query()->find($municipality->weekly_planning_owner_user_id)
            : null;

        return $owner !== null && $owner->active && $owner->hasAnyRole(['admin', 'agent_communal']) ? $owner : null;
    }

    /** @return Collection<int, User> */
    public function recipients(): Collection
    {
        $owner = $this->settings()['owner'];

        return $this->notificationRecipientResolver->internalUsers()
            ->filter(function (User $user) use ($owner): bool {
                $choice = $this->notificationPreferenceService->forUser($user)->weekly_planning_opt_in;

                return match ($choice) {
                    true => true,
                    false => false,
                    default => $owner === null || $owner->is($user),
                };
            })
            ->values();
    }

    /**
     * Responsable du planning changé par un admin (page Communes) : historique, puis la personne désignée est prévenue
     * (et l'ancienne, qu'elle ne l'est plus).
     */
    public function ownerChanged(Municipality $municipality, ?int $previousOwnerId, User $actor): void
    {
        $newOwnerId = $municipality->weekly_planning_owner_user_id;

        if ($newOwnerId === $previousOwnerId) {
            return;
        }

        $this->eventLogger->log(
            aggregateType: 'municipality',
            aggregateId: $municipality->id,
            eventType: 'weekly_planning.owner_changed',
            actor: $actor,
            oldValues: ['weekly_planning_owner_user_id' => $previousOwnerId],
            newValues: ['weekly_planning_owner_user_id' => $newOwnerId]
        );

        $actorName = trim($actor->first_name.' '.$actor->last_name);

        $this->notifyOwner(
            $newOwnerId,
            'planning.weekly.owner_assigned',
            'Vous êtes responsable du planning hebdomadaire',
            "{$actorName} vous a désigné responsable du planning hebdomadaire : vous le recevrez chaque semaine, ainsi que ses versions corrigées. Les autres agents ne le reçoivent plus, sauf s'ils l'activent."
        );
        $this->notifyOwner(
            $previousOwnerId,
            'planning.weekly.owner_removed',
            'Vous n’êtes plus responsable du planning hebdomadaire',
            "{$actorName} a changé le responsable du planning hebdomadaire. Vous pouvez continuer à le recevoir en l'activant dans vos notifications."
        );
    }

    private function notifyOwner(?int $userId, string $eventKey, string $title, string $message): void
    {
        $user = $userId !== null ? User::query()->find($userId) : null;

        if ($user === null) {
            return;
        }

        $this->notificationDispatchService->dispatch(
            users: collect([$user]),
            eventKey: $eventKey,
            category: 'admin_workflow',
            title: $title,
            message: $message,
            link: ['path' => '/compte#notifications', 'label' => 'Mes notifications']
        );
    }

    /** Moment de l'envoi de la semaine qui commence `$monday` (heure de Bruxelles) */
    public function sendMoment(CarbonImmutable $monday, array $settings): CarbonImmutable
    {
        [$hour, $minute] = array_map('intval', explode(':', $settings['send_time']));

        return $monday->addDays($settings['send_day'] - 1)->setTime($hour, $minute);
    }

    /**
     * Génère et envoie une version du planning de la semaine qui commence `$weekStart`.
     * Null si cette version vient d'être envoyée par un autre passage (ligne déjà réservée).
     */
    private function send(CarbonImmutable $weekStart, int $version): ?WeeklyPlanningDispatch
    {
        $trips = $this->trips($weekStart);
        $weekEnd = $weekStart->addDays(6);

        try {
            $dispatch = WeeklyPlanningDispatch::query()->create([
                'week_start' => $weekStart->toDateString(),
                'version' => $version,
                'signature' => $this->signature($trips),
                'trip_count' => $trips->count(),
                'pending_count' => $this->pendingCount($weekStart),
                'recipient_count' => 0,
                'sent_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            return null;
        }

        try {
            $document = $this->documentService->registerGeneratedDocument(
                documentType: DocumentType::PLANNING,
                directory: sprintf('documents/planning/hebdomadaire/%s', $weekStart->format('Y')),
                fileName: sprintf('planning-semaine-%s-v%d.xlsx', $weekStart->toDateString(), $version),
                content: $this->planningExportService->content($trips, $weekStart->toDateString(), $weekEnd->toDateString()),
                data: [
                    'generation_mode' => 'auto',
                    'generation_context' => ['weekly_planning' => true, 'week_start' => $weekStart->toDateString(), 'version' => $version],
                    'template_key' => 'planning.weekly',
                    'template_version' => '1.0',
                    'is_official' => true,
                    'version' => $version,
                    'service_date' => $weekStart->toDateString(),
                ]
            );
        } catch (Throwable $exception) {
            $dispatch->delete(); // le passage suivant réessaiera

            throw $exception;
        }

        // Même règle après le fichier : un envoi interrompu (base, historique…) ne doit pas compter comme fait. Le fichier
        // n'est plus proposé et le passage suivant refait cette version (les destinataires déjà prévenus le seront à nouveau).
        try {
            $recipients = $this->recipients();
            $dispatch->update(['document_id' => $document->id, 'recipient_count' => $recipients->count()]);

            $this->eventLogger->log(
                aggregateType: 'weekly_planning',
                aggregateId: $dispatch->id,
                eventType: $version === 1 ? 'weekly_planning.sent' : 'weekly_planning.updated',
                newValues: [
                    'week_start' => $weekStart->toDateString(),
                    'version' => $version,
                    'document_id' => $document->id,
                    'trip_count' => $dispatch->trip_count,
                    'recipient_count' => $recipients->count(),
                ]
            );

            $this->notificationDispatchService->dispatch(
                users: $recipients,
                eventKey: $version === 1 ? 'planning.weekly.sent' : 'planning.weekly.updated',
                category: 'weekly_planning',
                title: $this->title($weekStart, $version),
                message: $this->message($dispatch, $weekStart),
                document: $document,
                priority: 'normal',
                urgent: false,
                antiSpamScope: 'week:'.$weekStart->toDateString()
            );
        } catch (Throwable $exception) {
            $this->documentService->markUnavailable($document);
            $dispatch->delete();

            throw $exception;
        }

        return $dispatch->refresh();
    }

    /** Trajets du fichier : approuvés et confirmés de la semaine, tous bus et chauffeurs */
    private function trips(CarbonImmutable $weekStart): Collection
    {
        return $this->planningService->forService(
            $weekStart->toDateString(),
            $weekStart->addDays(6)->toDateString(),
            config('weekly_planning.statuses', [ReservationStatus::APPROUVE->value, ReservationStatus::CONFIRME->value])
        );
    }

    /** Demandes de la semaine qui ne sont pas dans le fichier (en attente ou en conflit) : annoncées dans le message */
    private function pendingCount(CarbonImmutable $weekStart): int
    {
        return Reservation::query()
            ->whereDate('trip_date', '>=', $weekStart->toDateString())
            ->whereDate('trip_date', '<=', $weekStart->addDays(6)->toDateString())
            ->whereIn('status', [ReservationStatus::EN_ATTENTE->value, ReservationStatus::EN_CONFLIT->value])
            ->count();
    }

    /** Empreinte de ce que montre le fichier : un changement de date, d'heure, de statut, de bus, de chauffeur, d'organisation… la change */
    private function signature(Collection $trips): string
    {
        return hash('sha256', $trips
            ->map(fn (Reservation $trip): string => implode('|', [
                $trip->id,
                $trip->status?->value,
                $trip->trip_date?->toDateString(),
                $trip->departure_time?->format('H:i'),
                $trip->return_time?->format('H:i'),
                $trip->driver_id,
                $trip->vehicle_id,
                $trip->organization_id,
                $trip->pickup_location_id,
                $trip->destination_location_id,
                (int) $trip->nb_children + (int) $trip->nb_adults,
                $trip->trip_purpose,
            ]))
            ->sort()
            ->implode("\n"));
    }

    private function title(CarbonImmutable $weekStart, int $version): string
    {
        $label = 'Planning de la semaine du '.$weekStart->format('d/m');

        return $version === 1 ? $label : "{$label} : version {$version} (corrigée)";
    }

    private function message(WeeklyPlanningDispatch $dispatch, CarbonImmutable $weekStart): string
    {
        $period = 'du '.$weekStart->format('d/m/Y').' au '.$weekStart->addDays(6)->format('d/m/Y');
        $trips = $dispatch->trip_count.' trajet'.($dispatch->trip_count > 1 ? 's' : '');
        $text = $dispatch->version === 1
            ? "Planning de la semaine {$period} : {$trips} approuvés ou confirmés."
            : "Un ou plusieurs trajets de la semaine {$period} ont changé. Version {$dispatch->version} : {$trips}. Elle remplace la précédente.";

        if ($dispatch->pending_count > 0) {
            $text .= " {$dispatch->pending_count} demande".($dispatch->pending_count > 1 ? 's' : '')
                .' de cette semaine '.($dispatch->pending_count > 1 ? 'sont' : 'est').' encore en attente ou en conflit (pas dans le fichier).';
        }

        return $text;
    }

    private function hasDispatch(CarbonImmutable $weekStart): bool
    {
        return WeeklyPlanningDispatch::query()->whereDate('week_start', $weekStart->toDateString())->exists();
    }

    private function timezone(): string
    {
        return config('reservations.local_timezone', 'Europe/Brussels');
    }
}
