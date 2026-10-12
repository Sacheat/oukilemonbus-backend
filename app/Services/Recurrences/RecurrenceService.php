<?php

namespace App\Services\Recurrences;

use App\Enums\OrganizationType;
use App\Enums\RecurrenceFrequency;
use App\Enums\ReservationStatus;
use App\Models\Organization;
use App\Models\Recurrence;
use App\Models\Reservation;
use App\Models\User;
use App\Services\Audit\DomainEventLogger;
use App\Services\Calendar\ClosedDaysService;
use App\Services\Logging\DomainLogger;
use App\Services\Reservations\ReservationPreviewService;
use App\Services\Reservations\ReservationService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Gère la création et l'annulation de récurrences de réservations.
 *
 * Réservées aux organisations pouvant utiliser les récurrences (ex. écoles — canUseRecurrences()).
 * Fréquences supportées : HEBDOMADAIRE, BIHEBDOMADAIRE (semaines paires depuis le lundi de départ),
 * MENSUEL (même jour du mois, addMonthNoOverflow pour éviter les débordements).
 *
 * `preview()` génère la liste des dates valides / ignorées (week-ends, jours fériés, délai minimum)
 * et détecte les conflits de façon approximative (sans appel ORS par occurrence).
 *
 * `create()` crée la Recurrence puis tente de créer chaque occurrence via ReservationService.
 * Les échecs individuels sont loggués et ignorés (resilient) ; `occurrence_count` est mis à jour
 * après coup pour refléter le nombre réellement créé (NULL si aucune réussite).
 *
 * `cancelRemaining()` annule toutes les réservations futures non terminales et passe
 * la Recurrence en is_active = false.
 */
class RecurrenceService
{
    public function __construct(
        private readonly ReservationService $reservationService,
        private readonly ReservationPreviewService $previewService,
        private readonly DomainEventLogger $eventLogger,
        private readonly DomainLogger $logger,
    ) {}

    public function preview(array $data): array
    {
        $organization = Organization::findOrFail($data['organization_id']);
        $this->ensureSchoolOrganization($organization);

        $frequency = RecurrenceFrequency::from($data['frequency']);
        $daysOfWeek = array_map('intval', $data['days_of_week'] ?? []);

        $allDates = $this->generateDates($frequency, $daysOfWeek, $data['start_date'], $data['end_date']);

        $validDates = [];
        $skippedDates = [];
        $conflictsByDate = [];
        $minNotice = (int) config('reservations.min_notice_days', 15);
        $minimumDate = CarbonImmutable::parse(Reservation::localToday())->addDays($minNotice);
        // Une instance pour toute la série : les périodes de la commune sont lues une fois, puis comparées en mémoire
        $closedDays = app(ClosedDaysService::class);

        foreach ($allDates as $date) {
            $skipReason = $this->skipReason($date, $closedDays);
            if ($skipReason !== null) {
                $skippedDates[] = ['date' => $date, 'reason' => $skipReason];
                continue;
            }

            // Préavis : pas pour une série créée par un agent ou un admin (skip_minimum_notice, mis par le contrôleur)
            if (! ($data['skip_minimum_notice'] ?? false) && CarbonImmutable::parse($date)->startOfDay()->lessThan($minimumDate)) {
                $skippedDates[] = [
                    'date' => $date,
                    'reason' => "Délai minimum de {$minNotice} jours non respecté.",
                ];
                continue;
            }

            $validDates[] = $date;

            // Conflict detection is approximate (no route distance calculated per date).
            $conflicts = $this->previewService->detectConflicts(
                array_merge($data, ['trip_date' => $date]),
                null,
                $organization
            );

            if ($conflicts !== []) {
                $conflictsByDate[$date] = $conflicts;
            }
        }

        return [
            'organization_id'         => $organization->id,
            'frequency'               => $frequency->value,
            'start_date'              => $data['start_date'],
            'end_date'                => $data['end_date'],
            'valid_dates'             => $validDates,
            'skipped_dates'           => $skippedDates,
            'conflicts_by_date'       => $conflictsByDate,
            'total_valid_occurrences' => count($validDates),
            'total_skipped'           => count($skippedDates),
            // Même limite que la vérification de capacité appliquée à chaque occurrence.
            'max_passengers'          => $this->previewService->maxPassengers(),
            // Les conflits sont estimés sans calcul de distance (ORS non appelé par occurrence).
            // La détection définitive et précise a lieu à la création de chaque occurrence.
            'conflicts_are_approximate' => true,
        ];
    }

    public function create(array $data, User $actor): Recurrence
    {
        return $this->createWithReport($data, $actor)['recurrence'];
    }

    /**
     * Crée la série et renvoie le rapport exposé par POST /recurrences :
     * dates ignorées au preview (avec motif) et dates dont la création a échoué.
     * Le motif d'un échec n'est détaillé que pour une erreur de validation métier.
     *
     * @return array{
     *     recurrence: Recurrence,
     *     created_count: int,
     *     skipped_dates: list<array{date: string, reason: string}>,
     *     failed_dates: list<array{date: string, reason: string}>
     * }
     */
    public function createWithReport(array $data, User $actor): array
    {
        // Un agent ou un admin peut déroger au préavis de 15 jours (demande téléphonée, urgence)
        $data['skip_minimum_notice'] = $actor->hasAnyRole(['admin', 'agent_communal']);
        $preview = $this->preview($data);

        if ($preview['valid_dates'] === []) {
            throw ValidationException::withMessages([
                'start_date' => 'Aucune date valide ne peut être générée pour cette récurrence (week-ends, jours fériés et délai minimum exclus).',
            ]);
        }

        return DB::transaction(function () use ($data, $preview, $actor): array {
            $frequency = RecurrenceFrequency::from($data['frequency']);
            $daysOfWeek = $data['days_of_week'] ?? [];

            // occurrence_count est initialisé à null et mis à jour après création effective,
            // pour rester cohérent avec la réalité même si certaines occurrences échouent.
            // La contrainte DB interdit 0 (NULL ou > 0 uniquement).
            $recurrence = Recurrence::create([
                'frequency'        => $frequency->value,
                'interval_value'   => $frequency->intervalDays() ?: 30,
                'days_of_week'     => $daysOfWeek !== [] ? implode(',', $daysOfWeek) : null,
                'start_date'       => $data['start_date'],
                'end_date'         => $data['end_date'],
                'occurrence_count' => null,
                'is_active'        => true,
            ]);

            $created = 0;
            $failedDates = [];
            $failures = [];

            foreach ($preview['valid_dates'] as $date) {
                try {
                    $this->reservationService->create(
                        array_merge($data, ['trip_date' => $date, 'recurrence_id' => $recurrence->id]),
                        $actor
                    );
                    $created++;
                } catch (\Throwable $e) {
                    $failedDates[] = $date;
                    $failures[] = [
                        'date'   => $date,
                        'reason' => $e instanceof ValidationException
                            ? (string) collect($e->errors())->flatten()->first()
                            : 'Erreur inattendue lors de la création de ce trajet.',
                    ];
                    logger()->warning('Recurrence occurrence skipped during create', [
                        'recurrence_id' => $recurrence->id,
                        'date'          => $date,
                        'error'         => $e->getMessage(),
                    ]);
                }
            }

            // Toujours synchronisé avec le nombre de réservations réellement créées.
            // NULL si aucune n'a pu être créée (contrainte DB : NULL ou > 0).
            $recurrence->update(['occurrence_count' => $created > 0 ? $created : null]);

            $this->logger->recurrenceCreated($recurrence, $actor, $created, count($preview['skipped_dates']));
            $this->eventLogger->log(
                aggregateType: 'recurrence',
                aggregateId: $recurrence->id,
                eventType: 'recurrence.created',
                actor: $actor,
                newValues: [
                    'frequency'        => $recurrence->frequency,
                    'start_date'       => $recurrence->start_date?->toDateString(),
                    'end_date'         => $recurrence->end_date?->toDateString(),
                    'occurrence_count' => $created,
                ],
                context: [
                    'preview_count'  => count($preview['valid_dates']),
                    'skipped_count'  => count($preview['skipped_dates']),
                    'failed_dates'   => $failedDates,
                ]
            );

            return [
                'recurrence'    => $recurrence->load(['reservations' => fn ($q) => $q->orderBy('trip_date')]),
                'created_count' => $created,
                'skipped_dates' => $preview['skipped_dates'],
                'failed_dates'  => $failures,
            ];
        });
    }

    public function cancelRemaining(Recurrence $recurrence, User $actor, string $reason): array
    {
        $toCancel = $recurrence->reservations()
            ->whereDate('trip_date', '>=', Reservation::localToday())
            ->whereNotIn('status', [ReservationStatus::REFUSE->value, ReservationStatus::ANNULE->value])
            ->orderBy('trip_date')
            ->get();

        $cancelled = [];

        foreach ($toCancel as $reservation) {
            try {
                $this->reservationService->cancel($reservation, $actor, $reason);
                $cancelled[] = $reservation->id;
            } catch (\Throwable $e) {
                logger()->warning('Recurrence occurrence cancel failed', [
                    'recurrence_id'  => $recurrence->id,
                    'reservation_id' => $reservation->id,
                    'error'          => $e->getMessage(),
                ]);
            }
        }

        if ($toCancel->isNotEmpty()) {
            $recurrence->update(['is_active' => false]);
            $this->logger->recurrenceCancelled($recurrence, $actor, count($cancelled));
            $this->eventLogger->log(
                aggregateType: 'recurrence',
                aggregateId: $recurrence->id,
                eventType: 'recurrence.cancelled',
                actor: $actor,
                context: ['cancelled_reservation_ids' => $cancelled]
            );
        }

        return $cancelled;
    }

    /**
     * Génère la liste brute de toutes les dates selon la fréquence et les jours demandés.
     * Les filtres (week-end, jours fériés, délai) sont appliqués séparément dans preview().
     *
     * @param  list<int>  $daysOfWeek  1 = lundi … 5 = vendredi (ISO)
     * @return list<string>
     */
    private function generateDates(RecurrenceFrequency $frequency, array $daysOfWeek, string $startDate, string $endDate): array
    {
        $start = CarbonImmutable::parse($startDate);
        $end = CarbonImmutable::parse($endDate);
        $dates = [];

        if ($frequency === RecurrenceFrequency::MENSUEL) {
            $current = $start;
            while ($current->lessThanOrEqualTo($end)) {
                $dates[] = $current->toDateString();
                $current = $current->addMonthNoOverflow();
            }

            return $dates;
        }

        // Hebdomadaire / bihebdomadaire : on itère jour par jour sur la plage.
        // Pour le bihebdomadaire, seules les semaines paires (depuis la semaine de start) sont actives.
        $referenceMonday = $start->startOfWeek(); // lundi de la semaine de départ
        $current = $start;

        while ($current->lessThanOrEqualTo($end)) {
            if (in_array($current->dayOfWeekIso, $daysOfWeek, true)) {
                if ($frequency === RecurrenceFrequency::BIHEBDOMADAIRE) {
                    $weeksDiff = (int) abs($referenceMonday->diffInWeeks($current->startOfWeek()));
                    if ($weeksDiff % 2 !== 0) {
                        $current = $current->addDay();
                        continue;
                    }
                }

                $dates[] = $current->toDateString();
            }

            $current = $current->addDay();
        }

        return $dates;
    }

    /**
     * Pourquoi une date de la série est sautée, ou null : week-end, jour férié légal, fermeture de la commune
     * (ClosedDaysService), puis vacances scolaires de la commune (« sauf pendant les vacances scolaires »).
     */
    private function skipReason(string $date, ClosedDaysService $closedDays): ?string
    {
        return $closedDays->closedReason($date) ?? $closedDays->schoolHolidayReason($date);
    }

    private function ensureSchoolOrganization(Organization $organization): void
    {
        if (! $organization->type?->canUseRecurrences()) {
            throw ValidationException::withMessages([
                'organization_id' => 'Les récurrences ne sont pas autorisées pour ce type d’organisation.',
            ]);
        }
    }
}
