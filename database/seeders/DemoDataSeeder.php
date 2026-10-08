<?php

namespace Database\Seeders;

use App\Enums\ApprovalType;
use App\Enums\CouncilDecisionStatus;
use App\Enums\DriverAlertStatus;
use App\Enums\OrganizationType;
use App\Enums\PositionState;
use App\Enums\RecurrenceFrequency;
use App\Enums\RequestStatus;
use App\Enums\ReservationActivityType;
use App\Enums\ReservationConflictType;
use App\Enums\ReservationStatus;
use App\Enums\TripEventSource;
use App\Enums\TripEventType;
use App\Enums\TripProgressState;
use App\Enums\TripReportOutcome;
use App\Enums\UnavailabilityReasonType;
use App\Models\Document;
use App\Models\Driver;
use App\Models\DriverAlert;
use App\Models\Location;
use App\Models\Organization;
use App\Models\OrgRegistrationRequest;
use App\Models\Recurrence;
use App\Models\Reservation;
use App\Models\ReservationConflict;
use App\Models\TripEvent;
use App\Models\TripReport;
use App\Models\Unavailability;
use App\Models\User;
use App\Models\UserOrgRequest;
use App\Models\Vehicle;
use App\Services\CouncilDecisions\CouncilDecisionService;
use App\Services\Documents\DocumentStorageService;
use App\Services\Documents\ProofDocumentService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Données de démonstration réalistes, pour parcourir toute l'application à la main (gestion, chauffeur, usager).
 * Nécessite les données de TestScenariosSeeder (comptes *.test, lieux, flotte de test).
 *
 *   php artisan db:seed --class=DemoDataSeeder                 retire puis recrée la démo (dates relatives à aujourd'hui)
 *   DEMO_CLEAR=1 php artisan db:seed --class=DemoDataSeeder    retire la démo seulement
 *
 * Lancer en journée : les trajets du jour (en route, sur place, pas encore parti…) sont placés autour de l'heure actuelle.
 * Tout ce qui est créé est marqué et retiré sans toucher au reste : motifs et organisations « [Démo] … », comptes
 *
 * demo.*@bus-communal.local, plaques DEMO-…, indisponibilités « [Démo] … ». Les comptes de test et la démo « Ma journée »
 * (DriverJourneyDemoSeeder) ne sont pas modifiés ; user.test est seulement ajouté aux organisations de démo.
 *
 * Deux parties :
 *   - couverture : une réservation par cas à voir, nommée selon ce qu'elle montre (« [Démo] En attente · Collège à décider ») ;
 *   - volume : quelques centaines de trajets ordinaires sur la flotte de démo, 6 semaines avant et après aujourd'hui,
 *     sans chevauchement (créneaux 08:30 et 13:30 par bus).
 * Les décisions du Collège passent par CouncilDecisionService (délibération PDF et notifications réelles) ; les états
 * impossibles à obtenir par l'API (passé, conflits souples, clôture, étapes du chauffeur) sont écrits directement.
 */
class DemoDataSeeder extends Seeder
{
    public const PREFIX = '[Démo]';

    private const EMAIL_PATTERN = 'demo.%@bus-communal.local';

    private const PLATE_PREFIX = 'DEMO-';

    private const CLOSURE_REASON = 'Le délai de cette réservation est désormais dépassé : elle a été clôturée. Pour plus d’informations, veuillez contacter un agent.';

    private CarbonImmutable $now;

    private string $today;

    private User $agent;

    /** @var array<string, Location> */
    private array $places = [];

    /** @var array<string, Organization> */
    private array $orgs = [];

    /** @var array<string, User> */
    private array $users = [];

    /** @var array<string, Driver> */
    private array $drivers = [];

    /** @var array<string, Vehicle> */
    private array $vehicles = [];

    public function run(): void
    {
        $this->clear();

        if (filter_var(env('DEMO_CLEAR', false), FILTER_VALIDATE_BOOLEAN)) {
            $this->command?->info('Données de démo retirées.');

            return;
        }

        $this->now = CarbonImmutable::now(config('reservations.local_timezone', 'Europe/Brussels'));
        $this->today = $this->now->toDateString();
        $this->agent = User::query()->where('email', 'agent.test@bus-communal.local')->firstOrFail();

        foreach (['seed-dinant-centre', 'seed-dinant-pickup', 'seed-dinant-destination', 'seed-anseremme', 'seed-givet'] as $placeId) {
            $this->places[$placeId] = Location::query()->where('place_id', $placeId)->firstOrFail();
        }

        DB::transaction(function (): void {
            $this->createPeople();
            $this->createFleet();
            $this->createVolume();
            $this->createQueueCases();
            $this->createTodayCases();
            $this->createPastCases();
            $this->createSeries();
            $this->createRequests();
            $this->createUnavailabilities();
            $this->createDriverAlerts();
            $this->adjustQuotas();
        });

        // Après la transaction : la délibération PDF et les notifications passent par les vrais services
        $this->createCouncilCases();

        $count = Reservation::query()->where('trip_purpose', 'like', self::PREFIX.'%')->count();
        $this->command?->info("Démo créée : {$count} réservations « ".self::PREFIX.' ».');
    }

    // ----- Nettoyage -----

    /**
     * Retire tout ce que la démo a créé, enfants avant parents (clés étrangères), sans toucher aux autres données.
     */
    public function clear(): void
    {
        $storage = app(DocumentStorageService::class);

        DB::transaction(function () use ($storage): void {
            $userIds = User::query()->where('email', 'like', self::EMAIL_PATTERN)->pluck('id');
            $orgIds = Organization::query()->where('name', 'like', self::PREFIX.'%')->pluck('id');
            $driverIds = Driver::query()->whereIn('user_id', $userIds)->pluck('id');
            $vehicleIds = Vehicle::query()->where('licence_plate', 'like', self::PLATE_PREFIX.'%')->pluck('id');
            $reservationIds = Reservation::query()
                ->where('trip_purpose', 'like', self::PREFIX.'%')
                ->orWhereIn('organization_id', $orgIds)
                ->pluck('id');
            $recurrenceIds = Reservation::query()->whereIn('id', $reservationIds)->whereNotNull('recurrence_id')->pluck('recurrence_id')->unique();

            // Documents liés aux réservations de démo (délibérations) et justificatifs des demandes de démo
            $proofIds = UserOrgRequest::query()->whereIn('requested_by_user_id', $userIds)->orWhereIn('organization_id', $orgIds)->pluck('proof_document_id')
                ->merge(OrgRegistrationRequest::query()->whereIn('request_by_user_id', $userIds)->pluck('proof_document_id'))
                ->filter();
            $documents = Document::query()
                ->whereIn('reservation_id', $reservationIds)
                ->orWhereIn('id', $proofIds)
                ->get();

            DB::table('document_reservation')->whereIn('reservation_id', $reservationIds)->delete();
            DriverAlert::query()->whereIn('driver_id', $driverIds)->orWhereIn('reservation_id', $reservationIds)->delete();
            DB::table('trip_events')->whereIn('reservation_id', $reservationIds)->delete();
            DB::table('trip_reports')->whereIn('reservation_id', $reservationIds)->delete();
            DB::table('reservation_conflicts')->whereIn('reservation_id', $reservationIds)->delete();
            DB::table('council_decisions')->whereIn('reservation_id', $reservationIds)->delete();
            DB::table('reservation_status_history')->whereIn('reservation_id', $reservationIds)->delete();
            DB::table('reservation_assignment_history')->whereIn('reservation_id', $reservationIds)->delete();
            UserOrgRequest::query()->whereIn('requested_by_user_id', $userIds)->orWhereIn('organization_id', $orgIds)->delete();
            OrgRegistrationRequest::query()->whereIn('request_by_user_id', $userIds)->delete();
            Document::query()->whereIn('id', $documents->pluck('id'))->delete();
            Reservation::query()->whereIn('id', $reservationIds)->delete();
            Recurrence::query()->whereIn('id', $recurrenceIds)->delete();
            Unavailability::query()
                ->whereIn('driver_id', $driverIds)
                ->orWhereIn('vehicle_id', $vehicleIds)
                ->orWhere('reason', 'like', self::PREFIX.'%')
                ->get()
                ->each(function (Unavailability $unavailability): void {
                    DB::table('unavailabilities_history')->where('unavailability_id', $unavailability->id)->delete();
                    $unavailability->delete();
                });
            DB::table('user_organization')->whereIn('organization_id', $orgIds)->orWhereIn('user_id', $userIds)->delete();
            DB::table('notifications')->where('notifiable_type', User::class)->whereIn('notifiable_id', $userIds)->delete();
            Driver::query()->whereIn('id', $driverIds)->delete();
            Vehicle::query()->whereIn('id', $vehicleIds)->delete();
            Organization::query()->whereIn('id', $orgIds)->delete();
            DB::table('model_has_roles')->where('model_type', User::class)->whereIn('model_id', $userIds)->delete();
            User::query()->whereIn('id', $userIds)->delete();

            $documents->each(fn (Document $document) => $document->file_path ? $storage->delete($document->file_path) : null);
        });
    }

    // ----- Acteurs -----

    private function createPeople(): void
    {
        $organizations = [
            'ecole' => ['École des Rivages', OrganizationType::ECOLE_COMMUNALE, 'ecole.rivages'],
            'travaux' => ['Service des Sports', OrganizationType::PERSONNEL_COMMUNAL, 'sports'],
            'cpas' => ['CPAS de Dinant', OrganizationType::CPAS, 'cpas'],
            'asbl' => ['ASBL Les Randonneurs', OrganizationType::ASBL, 'randonneurs'],
            'externe' => ['Société Ardennes Tours', OrganizationType::EXTERNE, 'ardennes.tours'],
        ];
        foreach ($organizations as $key => [$name, $type, $mail]) {
            $this->orgs[$key] = Organization::create([
                'name' => self::PREFIX.' '.$name,
                'type' => $type,
                'official_email' => "{$mail}@demo.test",
                'phone_contact' => '082 21 00 '.str_pad((string) (10 + count($this->orgs)), 2, '0', STR_PAD_LEFT),
                'name_contact' => 'Contact '.$name,
                'location_id' => $this->places['seed-dinant-centre']->id,
                'is_priority' => $type === OrganizationType::ECOLE_COMMUNALE,
                'annual_quota' => match ($type) {
                    OrganizationType::CPAS => 20,
                    OrganizationType::ASBL, OrganizationType::EXTERNE => 10,
                    default => 0,
                },
            ]);
        }
        $this->orgs['externe']->update(['is_banned' => true, 'banned_reason' => 'Réservations répétées sans se présenter (démo).']);

        $people = [
            'ecole' => ['Claire', 'Dubois', 'user', ['ecole' => 'directeur']],
            'travaux' => ['Marc', 'Lambert', 'user', ['travaux' => 'responsable']],
            'cpas' => ['Sofia', 'Renard', 'user', ['cpas' => 'responsable']],
            'asbl' => ['Hugo', 'Petit', 'user', ['asbl' => 'animateur']],
            'externe' => ['Nadia', 'Collin', 'user', ['externe' => 'responsable']],
            'candidat1' => ['Lucas', 'Martin', 'user', []],
            'candidat2' => ['Emma', 'Leroy', 'user', []],
            'candidat3' => ['Noah', 'Simon', 'user', []],
            'chauffeur1' => ['Paul', 'Gérard', 'chauffeur', []],
            'chauffeur2' => ['Inès', 'Moreau', 'chauffeur', []],
            'chauffeur3' => ['Tom', 'Laurent', 'chauffeur', []],
            'chauffeur4' => ['Julie', 'Fontaine', 'chauffeur', []],
        ];
        foreach ($people as $key => [$first, $last, $role, $memberships]) {
            $user = User::create([
                'first_name' => $first,
                'last_name' => $last,
                'email' => "demo.{$key}@bus-communal.local",
                'password' => 'password',
                'phone' => '0470 00 00 '.str_pad((string) (10 + count($this->users)), 2, '0', STR_PAD_LEFT),
                'active' => true,
            ]);
            $user->forceFill(['email_verified_at' => now()])->save();
            $user->assignRole($role);
            foreach ($memberships as $org => $relationship) {
                $this->orgs[$org]->users()->attach($user->id, ['relationship_type' => $relationship, 'is_default' => true]);
            }
            $this->users[$key] = $user;
        }

        // user.test voit la démo depuis son espace : responsable de l'ASBL, membre de l'école
        $userTest = User::query()->where('email', 'user.test@bus-communal.local')->firstOrFail();
        $this->orgs['asbl']->users()->attach($userTest->id, ['relationship_type' => 'responsable', 'is_default' => false]);
        $this->orgs['ecole']->users()->attach($userTest->id, ['relationship_type' => 'prof', 'is_default' => false]);
        $this->users['user.test'] = $userTest;
    }

    private function createFleet(): void
    {
        $this->vehicles = [
            'bus1' => Vehicle::create(['licence_plate' => 'DEMO-101', 'capacity' => 50, 'is_active' => true]),
            'bus2' => Vehicle::create(['licence_plate' => 'DEMO-102', 'capacity' => 41, 'is_active' => true]),
            'bus3' => Vehicle::create(['licence_plate' => 'DEMO-103', 'capacity' => 30, 'is_active' => true]),
            'minibus' => Vehicle::create(['licence_plate' => 'DEMO-104', 'capacity' => 16, 'is_active' => true]),
            'ancien' => Vehicle::create(['licence_plate' => 'DEMO-105', 'capacity' => 35, 'is_active' => false]),
        ];

        $licences = [
            'chauffeur1' => $this->now->addYears(3),
            'chauffeur2' => $this->now->addYears(2),
            'chauffeur3' => $this->now->addDays(10), // permis qui expire bientôt
            'chauffeur4' => $this->now->subDays(5),  // permis expiré : aucune mission
        ];
        foreach ($licences as $key => $expiry) {
            $this->drivers[$key] = Driver::create([
                'user_id' => $this->users[$key]->id,
                'licence_number' => 'DEMO-LIC-'.strtoupper(substr($key, -1)),
                'licence_expiry_date' => $expiry->toDateString(),
                'is_active' => true,
            ]);
        }
        // chauffeur.test reçoit aussi des missions de démo (sur le 3e bus, une semaine sur deux)
        $this->drivers['test'] = Driver::query()->where('licence_number', 'DRV-SEED-001')->firstOrFail();
    }

    // ----- Volume : trajets ordinaires sur la flotte de démo -----

    private function createVolume(): void
    {
        $purposes = [
            'Piscine', 'Visite du musée', 'Sortie nature', 'Tournoi sportif', 'Atelier cuisine',
            'Visite de la bibliothèque', 'Classe verte', 'Spectacle au centre culturel', 'Excursion à la citadelle',
            'Formation du personnel', 'Visite d’un chantier', 'Sortie des aînés', 'Marché de Noël', 'Balade en forêt',
        ];
        $orgCycle = ['ecole', 'ecole', 'travaux', 'ecole', 'cpas', 'travaux', 'asbl'];
        $slots = [['08:30', '10:30'], ['13:30', '15:30']];
        $buses = ['bus1' => 'chauffeur1', 'bus2' => 'chauffeur2', 'bus3' => null];
        $destinations = ['seed-anseremme', 'seed-givet', 'seed-dinant-destination'];
        $index = 0;

        for ($offset = -42; $offset <= 42; $offset++) {
            $day = $this->now->addDays($offset);
            if ($day->isWeekend() || $offset === 0) {
                continue;
            }
            foreach ($buses as $bus => $driverKey) {
                foreach ($slots as $slotIndex => [$departure, $return]) {
                    // Environ 55 % des créneaux occupés, réparti de façon stable (pas de hasard : relançable à l'identique)
                    if ((($offset * 7 + strlen($bus) * 3 + $slotIndex * 5 + ord($bus[3])) % 20) >= 11) {
                        continue;
                    }
                    $driverKey ??= $day->weekOfYear % 2 === 0 ? 'test' : 'chauffeur3';
                    $orgKey = $orgCycle[$index % count($orgCycle)];
                    $past = $offset < 0;
                    $reservation = $this->reservation([
                        'org' => $orgKey,
                        'date' => $day->toDateString(),
                        'departure' => $departure,
                        'return' => $return,
                        'purpose' => $purposes[$index % count($purposes)],
                        'destination' => $destinations[$index % count($destinations)],
                        'status' => ReservationStatus::CONFIRME,
                        'driver' => $driverKey,
                        'vehicle' => $bus,
                        'children' => 8 + ($index % 30),
                        'adults' => 1 + ($index % 3),
                        'passengers_confirmed' => $past || $offset > 14 || $index % 4 !== 0,
                    ]);
                    if ($past) {
                        $this->report($reservation, $index % 17 === 0 ? TripReportOutcome::NON_REALISE : TripReportOutcome::REALISE);
                    }
                    if (in_array($orgKey, ['cpas', 'asbl'], true)) {
                        $this->recordDecisionDirectly($reservation, CouncilDecisionStatus::APPROUVE);
                    }
                    $driverKey = $buses[$bus];
                    $index++;
                }
            }
        }
    }

    // ----- Couverture : files de la gestion -----

    private function createQueueCases(): void
    {
        // À traiter : en attente
        $this->reservation(['org' => 'cpas', 'days' => 1, 'departure' => '10:00', 'return' => '12:00', 'purpose' => 'En attente · urgente, trajet tout proche (Collège à décider)', 'status' => ReservationStatus::EN_ATTENTE]);
        $this->reservation(['org' => 'asbl', 'days' => 3, 'departure' => '14:00', 'return' => '17:00', 'purpose' => 'En attente · bientôt (Collège à décider)', 'status' => ReservationStatus::EN_ATTENTE]);
        $this->reservation(['org' => 'asbl', 'days' => 20, 'departure' => '09:00', 'return' => '16:00', 'purpose' => 'En attente · Collège à décider', 'status' => ReservationStatus::EN_ATTENTE, 'destination' => 'seed-givet']);

        // À traiter : en conflit (un cas par type de conflit souple)
        $first = $this->reservation(['org' => 'asbl', 'days' => 24, 'departure' => '09:00', 'return' => '12:00', 'purpose' => 'En attente · créneau disputé (autre demande)', 'status' => ReservationStatus::EN_ATTENTE]);
        $this->conflict(
            $this->reservation(['org' => 'cpas', 'days' => 24, 'departure' => '10:00', 'return' => '12:30', 'purpose' => 'En conflit · chevauchement avec une autre demande', 'status' => ReservationStatus::EN_CONFLIT]),
            ReservationConflictType::SCHEDULE_OVERLAP,
            'Le créneau chevauche une autre demande en attente sur le même bus.',
            $first,
        );
        $this->conflict(
            $this->reservation(['org' => 'asbl', 'days' => 27, 'departure' => '13:00', 'return' => '16:00', 'purpose' => 'En conflit · quota annuel dépassé', 'status' => ReservationStatus::EN_CONFLIT]),
            ReservationConflictType::QUOTA_EXCEEDED,
            'L’organisation a atteint son quota annuel de réservations.',
        );
        $this->conflict(
            $this->reservation(['org' => 'asbl', 'days' => 30, 'departure' => '06:45', 'return' => '11:00', 'purpose' => 'En conflit · départ avant l’heure autorisée (Collège exigé)', 'status' => ReservationStatus::EN_CONFLIT]),
            ReservationConflictType::DEPARTURE_BEFORE_ALLOWED_TIME,
            'Départ à 06:45, avant l’heure autorisée (07:30).',
        );
        $this->conflict(
            $this->reservation(['org' => 'travaux', 'days' => 9, 'departure' => '06:30', 'return' => '09:00', 'purpose' => 'En conflit · dérogation horaire (Collège facultatif)', 'status' => ReservationStatus::EN_CONFLIT]),
            ReservationConflictType::DEPARTURE_BEFORE_ALLOWED_TIME,
            'Départ à 06:30, avant l’heure autorisée (07:30).',
        );
        $this->conflict(
            $this->reservation(['org' => 'ecole', 'days' => 6, 'departure' => '11:00', 'return' => '12:30', 'purpose' => 'En conflit · aucun chauffeur disponible', 'status' => ReservationStatus::EN_CONFLIT, 'vehicle' => 'minibus', 'children' => 12]),
            ReservationConflictType::NO_DRIVER_AVAILABLE,
            'Aucun chauffeur n’est libre sur ce créneau.',
        );

        // À affecter, puis prête à confirmer
        $this->reservation(['org' => 'ecole', 'days' => 5, 'departure' => '16:00', 'return' => '17:30', 'purpose' => 'Approuvée · sans chauffeur', 'status' => ReservationStatus::APPROUVE, 'vehicle' => 'minibus', 'children' => 12]);
        $this->reservation(['org' => 'travaux', 'days' => 8, 'departure' => '16:00', 'return' => '18:00', 'purpose' => 'Approuvée · sans véhicule', 'status' => ReservationStatus::APPROUVE, 'driver' => 'chauffeur1']);
        $this->reservation(['org' => 'ecole', 'days' => 12, 'departure' => '16:00', 'return' => '17:30', 'purpose' => 'Approuvée · affectée, à confirmer', 'status' => ReservationStatus::APPROUVE, 'driver' => 'chauffeur2', 'vehicle' => 'minibus', 'children' => 12]);

        // Suivi : confirmées, passagers à confirmer (dans les 14 jours) ou déjà confirmés
        $this->reservation(['org' => 'ecole', 'days' => 4, 'departure' => '16:00', 'return' => '17:30', 'purpose' => 'Confirmée · passagers à confirmer', 'status' => ReservationStatus::CONFIRME, 'driver' => 'chauffeur1', 'vehicle' => 'minibus', 'children' => 14, 'passengers_confirmed' => false]);
        $this->reservation(['org' => 'cpas', 'days' => 11, 'departure' => '16:00', 'return' => '18:00', 'purpose' => 'Confirmée · passagers confirmés', 'status' => ReservationStatus::CONFIRME, 'driver' => 'chauffeur1', 'vehicle' => 'bus1', 'decision' => true]);
        $this->reservation(['org' => 'ecole', 'days' => 2, 'departure' => '16:00', 'return' => '17:30', 'purpose' => 'Confirmée · gymnastique (créneau prioritaire)', 'status' => ReservationStatus::CONFIRME, 'driver' => 'chauffeur2', 'vehicle' => 'minibus', 'children' => 15, 'activity' => ReservationActivityType::GYMNASTIQUE]);
    }

    // ----- Couverture : la journée en cours (tableau de bord, Planning, Ma journée, demandes d'aide) -----

    private function createTodayCases(): void
    {
        $nowMinutes = $this->now->hour * 60 + $this->now->minute;
        $at = fn (int $delta): string => sprintf('%02d:%02d', intdiv(max(360, min(1410, $nowMinutes + $delta)), 60), max(360, min(1410, $nowMinutes + $delta)) % 60);

        $done = $this->reservation(['org' => 'ecole', 'days' => 0, 'departure' => $at(-360), 'return' => $at(-270), 'purpose' => 'Aujourd’hui · terminé (compte rendu réalisé)', 'status' => ReservationStatus::CONFIRME, 'driver' => 'chauffeur1', 'vehicle' => 'bus1']);
        $this->events($done, [TripEventType::DEPART_ALLER, TripEventType::ARRIVEE_ALLER, TripEventType::DEPART_RETOUR, TripEventType::ARRIVEE_RETOUR], -358);
        $this->report($done, TripReportOutcome::REALISE);

        $notDone = $this->reservation(['org' => 'travaux', 'days' => 0, 'departure' => $at(-300), 'return' => $at(-200), 'purpose' => 'Aujourd’hui · non réalisé (bus en panne)', 'status' => ReservationStatus::CONFIRME, 'driver' => 'chauffeur2', 'vehicle' => 'bus2']);
        $this->report($notDone, TripReportOutcome::NON_REALISE, 'Le bus ne démarrait pas, sortie reportée.');

        $onRoad = $this->reservation(['org' => 'ecole', 'days' => 0, 'departure' => $at(-25), 'return' => $at(95), 'purpose' => 'Aujourd’hui · en route', 'status' => ReservationStatus::CONFIRME, 'driver' => 'chauffeur1', 'vehicle' => 'bus1', 'destination' => 'seed-givet']);
        $this->events($onRoad, [TripEventType::DEPART_ALLER], -23);

        $onSite = $this->reservation(['org' => 'cpas', 'days' => 0, 'departure' => $at(-80), 'return' => $at(40), 'purpose' => 'Aujourd’hui · sur place', 'status' => ReservationStatus::CONFIRME, 'driver' => 'chauffeur2', 'vehicle' => 'bus2', 'decision' => true, 'destination' => 'seed-anseremme']);
        $this->events($onSite, [TripEventType::DEPART_ALLER, TripEventType::ARRIVEE_ALLER], -78);

        $this->reservation(['org' => 'ecole', 'days' => 0, 'departure' => $at(-15), 'return' => $at(75), 'purpose' => 'Aujourd’hui · pas encore parti (heure passée)', 'status' => ReservationStatus::CONFIRME, 'driver' => 'chauffeur3', 'vehicle' => 'bus3']);
        $this->reservation(['org' => 'travaux', 'days' => 0, 'departure' => $at(150), 'return' => $at(240), 'purpose' => 'Aujourd’hui · prochain départ', 'status' => ReservationStatus::CONFIRME, 'driver' => 'chauffeur3', 'vehicle' => 'bus3']);
        $this->reservation(['org' => 'ecole', 'days' => 0, 'departure' => $at(180), 'return' => $at(260), 'purpose' => 'Aujourd’hui · non affecté', 'status' => ReservationStatus::APPROUVE, 'children' => 12]);
    }

    // ----- Couverture : le passé -----

    private function createPastCases(): void
    {
        // Compte rendu attendu : hier pour chauffeur.test (encore dans son délai), avant-hier pour un chauffeur de démo
        $this->reservation(['org' => 'ecole', 'days' => -1, 'departure' => '18:00', 'return' => '19:00', 'purpose' => 'Récent · compte rendu à faire (chauffeur.test)', 'status' => ReservationStatus::CONFIRME, 'driver' => 'test', 'vehicle' => 'minibus', 'children' => 12]);
        $this->reservation(['org' => 'travaux', 'days' => -2, 'departure' => '16:30', 'return' => '18:00', 'purpose' => 'Passé · sans compte rendu', 'status' => ReservationStatus::CONFIRME, 'driver' => 'chauffeur3', 'vehicle' => 'minibus', 'children' => 10]);

        $this->reservation(['org' => 'asbl', 'days' => -10, 'departure' => '09:00', 'return' => '12:00', 'purpose' => 'Passé · refusée par un agent', 'status' => ReservationStatus::REFUSE, 'rejection' => 'Aucun bus disponible ce jour-là (démo).']);
        $this->reservation(['org' => 'ecole', 'days' => -7, 'departure' => '09:00', 'return' => '11:00', 'purpose' => 'Passé · annulée par le demandeur', 'status' => ReservationStatus::ANNULE, 'cancelled_by' => 'ecole', 'cancellation' => 'Sortie reportée à cause de la météo.']);
        $this->reservation(['org' => 'cpas', 'days' => -5, 'departure' => '14:00', 'return' => '16:00', 'purpose' => 'Passé · annulée par un agent', 'status' => ReservationStatus::ANNULE, 'cancelled_by' => 'agent', 'cancellation' => 'Bus réquisitionné pour une urgence communale (démo).']);
        $this->reservation(['org' => 'asbl', 'days' => -3, 'departure' => '10:00', 'return' => '12:00', 'purpose' => 'Passé · clôturée automatiquement (jamais validée)', 'status' => ReservationStatus::ANNULE, 'cancellation' => self::CLOSURE_REASON]);
        $this->reservation(['org' => 'externe', 'days' => -20, 'departure' => '09:00', 'return' => '17:00', 'purpose' => 'Passé · organisation bannie depuis', 'status' => ReservationStatus::ANNULE, 'cancelled_by' => 'agent', 'cancellation' => 'Organisation bannie (démo).']);
    }

    // ----- Couverture : une série d'école (récurrence hebdomadaire) -----

    private function createSeries(): void
    {
        $start = $this->now->subWeeks(3)->startOfWeek()->addDay(); // un mardi, il y a 3 semaines
        $recurrence = Recurrence::create([
            'frequency' => RecurrenceFrequency::HEBDOMADAIRE->value,
            'interval_value' => 1,
            'days_of_week' => '2',
            'start_date' => $start->toDateString(),
            'end_date' => $start->addWeeks(7)->toDateString(),
            'occurrence_count' => 8,
            'is_active' => true,
        ]);

        for ($week = 0; $week < 8; $week++) {
            $day = $start->addWeeks($week);
            $past = $day->toDateString() < $this->today;
            $cancelled = in_array($week, [2, 5], true);
            $reservation = $this->reservation([
                'org' => 'ecole',
                'date' => $day->toDateString(),
                'departure' => '16:15',
                'return' => '17:45',
                'purpose' => 'Série · natation du mardi',
                'status' => $cancelled ? ReservationStatus::ANNULE : ReservationStatus::CONFIRME,
                'cancelled_by' => $cancelled ? 'ecole' : null,
                'cancellation' => $cancelled ? 'Piscine fermée cette semaine.' : null,
                'driver' => $cancelled ? null : 'test',
                'vehicle' => $cancelled ? null : 'bus3',
                'children' => 22,
                'recurrence' => $recurrence,
                'destination' => 'seed-dinant-destination',
            ]);
            if ($past && ! $cancelled) {
                $this->report($reservation, TripReportOutcome::REALISE);
            }
        }
    }

    // ----- Couverture : décisions du Collège (vrais services : délibération PDF, notifications) -----

    private function createCouncilCases(): void
    {
        $service = app(CouncilDecisionService::class);

        $pending = $this->reservation(['org' => 'asbl', 'days' => 18, 'departure' => '09:00', 'return' => '12:00', 'purpose' => 'Collège · décision encore en attente', 'status' => ReservationStatus::EN_ATTENTE]);
        $ready = $this->reservation(['org' => 'cpas', 'days' => 15, 'departure' => '13:00', 'return' => '16:00', 'purpose' => 'Collège favorable · prête à approuver', 'status' => ReservationStatus::EN_ATTENTE]);
        $refused = $this->reservation(['org' => 'asbl', 'days' => 21, 'departure' => '08:00', 'return' => '18:00', 'purpose' => 'Collège défavorable · réservation refusée', 'status' => ReservationStatus::EN_ATTENTE, 'destination' => 'seed-givet']);
        $optional = $this->reservation(['org' => 'ecole', 'days' => 16, 'departure' => '06:40', 'return' => '09:00', 'purpose' => 'Collège facultatif · dérogation accordée (école)', 'status' => ReservationStatus::EN_CONFLIT, 'children' => 18]);
        $this->conflict($optional, ReservationConflictType::DEPARTURE_BEFORE_ALLOWED_TIME, 'Départ à 06:40, avant l’heure autorisée (07:30).');

        $decisions = [
            [$pending, CouncilDecisionStatus::EN_ATTENTE, null],
            [$ready, CouncilDecisionStatus::APPROUVE, 'Sortie d’intérêt social approuvée.'],
            [$refused, CouncilDecisionStatus::REFUSE, 'Trajet trop long pour un seul chauffeur (démo).'],
            [$optional, CouncilDecisionStatus::APPROUVE, 'Dérogation horaire accordée pour la classe de mer.'],
        ];
        foreach ($decisions as $index => [$reservation, $status, $notes]) {
            $service->create([
                'reservation_id' => $reservation->id,
                'decision_status' => $status->value,
                'decision_date' => $status === CouncilDecisionStatus::EN_ATTENTE ? null : $this->today,
                'decision_reference' => $status === CouncilDecisionStatus::EN_ATTENTE ? null : sprintf('DEMO-COL-%03d', $index + 1),
                'notes' => $notes,
            ], $this->agent);
        }
    }

    // ----- Couverture : demandes d'adhésion et de création d'organisation -----

    private function createRequests(): void
    {
        $proof = fn (string $owner, int $id) => app(ProofDocumentService::class)->store(
            UploadedFile::fake()->createWithContent('justificatif.pdf', $this->tinyPdf()),
            $owner,
            $id,
        );

        $join = fn (string $user, string $org, string $role, RequestStatus $status, int $note, array $extra = []) => UserOrgRequest::create([
            'organization_id' => $this->orgs[$org]->id,
            'requested_by_user_id' => $this->users[$user]->id,
            'requested_role' => $role,
            'justification' => $extra['justification'] ?? 'Je souhaite réserver le bus pour mes activités.',
            'status' => $status,
            'confidence_note' => $note,
            'reviewed_by_user_id' => $status === RequestStatus::EN_ATTENTE ? null : $this->agent->id,
            'reviewed_at' => $status === RequestStatus::EN_ATTENTE ? null : $this->now->subDays(2),
            'rejection_reason' => $extra['rejection'] ?? null,
        ]);

        $withProof = $join('candidat1', 'ecole', 'prof', RequestStatus::EN_ATTENTE, 85, ['justification' => 'Institutrice de 3e primaire, j’organise les sorties de ma classe.']);
        $withProof->update(['proof_document_id' => $proof('user-org-requests', $withProof->request_id)->id]);
        $join('candidat2', 'asbl', 'responsable', RequestStatus::EN_ATTENTE, 40, ['justification' => 'Nouveau président de l’ASBL (rôle déjà tenu : avertissement attendu).']);
        $join('candidat3', 'cpas', 'referent', RequestStatus::EN_ATTENTE, 60);
        $join('candidat3', 'travaux', 'animateur', RequestStatus::APPROUVE, 75);
        $join('candidat2', 'ecole', 'animateur', RequestStatus::REFUSE, 20, ['rejection' => 'Aucun lien avec l’école (démo).']);

        $creation = fn (string $user, string $name, OrganizationType $type, RequestStatus $status, int $note, array $extra = []) => OrgRegistrationRequest::create([
            'request_by_user_id' => $this->users[$user]->id,
            'organization_name' => self::PREFIX.' '.$name,
            'organization_type' => $type,
            'enterprise_number' => $extra['bce'] ?? null,
            'official_email' => strtolower(str_replace(' ', '.', $name)).'@demo.test',
            'phone' => '082 22 00 00',
            'location_id' => $this->places['seed-dinant-centre']->id,
            'contact_person_name' => $this->users[$user]->first_name.' '.$this->users[$user]->last_name,
            'contact_person_role' => 'Président',
            'comment' => $extra['comment'] ?? 'Nous organisons des sorties pour nos membres.',
            'status' => $status,
            'confidence_note' => $note,
            'reviewed_by_user_id' => $status === RequestStatus::EN_ATTENTE ? null : $this->agent->id,
            'reviewed_at' => $status === RequestStatus::EN_ATTENTE ? null : $this->now->subDays(4),
            'rejection_reason' => $extra['rejection'] ?? null,
        ]);

        $club = $creation('candidat1', 'Club de basket de Dinant', OrganizationType::ASBL, RequestStatus::EN_ATTENTE, 90, ['bce' => '0123.456.749']);
        $club->update(['proof_document_id' => $proof('org-registration-requests', $club->id)->id]);
        $creation('candidat2', 'Comité des fêtes de Leffe', OrganizationType::EXTERNE, RequestStatus::EN_ATTENTE, 35, ['comment' => 'Fête de quartier en juin.']);
        $creation('candidat3', 'Maison de jeunes', OrganizationType::ASBL, RequestStatus::REFUSE, 25, ['rejection' => 'Organisation déjà enregistrée sous un autre nom (démo).']);
    }

    // ----- Couverture : flotte (indisponibilités) -----

    private function createUnavailabilities(): void
    {
        $at = fn (int $days, string $time): string => $this->now->addDays($days)->toDateString().' '.$time.':00';
        $make = fn (array $attributes) => Unavailability::create([
            'created_by_user_id' => $this->agent->id,
            'is_active' => true,
            ...$attributes,
        ]);

        $make(['reason_type' => UnavailabilityReasonType::VEHICLE, 'vehicle_id' => $this->vehicles['minibus']->id, 'reason' => self::PREFIX.' Contrôle technique', 'start_datetime' => $at(0, '07:00'), 'end_datetime' => $at(0, '19:00')]);
        $make(['reason_type' => UnavailabilityReasonType::DRIVER, 'driver_id' => $this->drivers['chauffeur4']->id, 'reason' => self::PREFIX.' Congé', 'start_datetime' => $at(1, '07:00'), 'end_datetime' => $at(3, '19:00')]);
        $make(['reason_type' => UnavailabilityReasonType::DRIVER, 'driver_id' => $this->drivers['chauffeur4']->id, 'reason' => self::PREFIX.' Formation', 'start_datetime' => $at(-9, '08:00'), 'end_datetime' => $at(-9, '17:00')]);
        $make(['reason_type' => UnavailabilityReasonType::VEHICLE, 'vehicle_id' => $this->vehicles['ancien']->id, 'reason' => self::PREFIX.' Révision annulée', 'start_datetime' => $at(6, '07:00'), 'end_datetime' => $at(6, '19:00'), 'is_active' => false]);
    }

    // ----- Couverture : demandes d'aide des chauffeurs -----

    private function createDriverAlerts(): void
    {
        $onRoad = Reservation::query()->where('trip_purpose', self::PREFIX.' Aujourd’hui · en route')->firstOrFail();
        $onSite = Reservation::query()->where('trip_purpose', self::PREFIX.' Aujourd’hui · sur place')->firstOrFail();
        $givet = $this->places['seed-givet'];
        $anseremme = $this->places['seed-anseremme'];

        $alert = function (Reservation $reservation, string $driver, array $attributes): void {
            $createdAt = $attributes['created_at'];
            unset($attributes['created_at']);
            DriverAlert::create([
                'driver_id' => $this->drivers[$driver]->id,
                'reservation_id' => $reservation->id,
                'vehicle_id' => $reservation->vehicle_id,
                'created_by_user_id' => $this->users[$driver]->id,
                'position_updates' => 1,
                ...$attributes,
            ])->forceFill(['created_at' => $createdAt])->saveQuietly();
        };

        $alert($onRoad, 'chauffeur1', [
            'status' => DriverAlertStatus::OUVERTE,
            'message' => 'Pneu crevé sur la N96, je suis arrêté sur le bas-côté.',
            'trip_progress' => TripProgressState::EN_ROUTE_ALLER,
            'position_state' => PositionState::ENVOYEE,
            'latitude' => (float) $givet->latitude - 0.05,
            'longitude' => (float) $givet->longitude,
            'accuracy_m' => 12,
            'position_captured_at' => now()->subMinutes(4),
            'created_at' => now()->subMinutes(4),
        ]);
        $alert($onSite, 'chauffeur2', [
            'status' => DriverAlertStatus::PRISE_EN_CHARGE,
            'message' => 'Un enfant ne se sent pas bien, j’attends les parents.',
            'trip_progress' => TripProgressState::SUR_PLACE,
            'position_state' => PositionState::ENVOYEE,
            'latitude' => (float) $anseremme->latitude,
            'longitude' => (float) $anseremme->longitude,
            'accuracy_m' => 20,
            'position_captured_at' => now()->subMinutes(25),
            'handled_by_user_id' => $this->agent->id,
            'handled_at' => now()->subMinutes(20),
            'created_at' => now()->subMinutes(25),
        ]);
        $alert($onSite, 'chauffeur2', [
            'status' => DriverAlertStatus::CLOTUREE,
            'message' => 'Route barrée à Anseremme.',
            'trip_progress' => TripProgressState::EN_ROUTE_ALLER,
            'position_state' => PositionState::REFUSEE,
            'handled_by_user_id' => $this->agent->id,
            'handled_at' => now()->subDay()->subMinutes(50),
            'closed_by_user_id' => $this->agent->id,
            'closed_at' => now()->subDay()->subMinutes(30),
            'closing_note' => 'Itinéraire de délestage communiqué par téléphone.',
            'created_at' => now()->subDay()->subHour(),
        ]);
    }

    // ----- Quotas : l'ASBL presque au plafond -----

    private function adjustQuotas(): void
    {
        $year = $this->now->year;
        $used = Reservation::query()
            ->where('organization_id', $this->orgs['asbl']->id)
            ->whereYear('trip_date', $year)
            ->whereNotIn('status', [ReservationStatus::REFUSE->value, ReservationStatus::ANNULE->value])
            ->count();
        $this->orgs['asbl']->update(['annual_quota' => $used + 1]);
    }

    // ----- Outils -----

    /**
     * Une réservation de démo. Clés : org, date ou days (décalage depuis aujourd'hui), departure, return, purpose
     * (préfixé « [Démo] »), status, driver, vehicle, destination, children, adults, passengers_confirmed, activity,
     * rejection, cancellation, cancelled_by, recurrence, decision (Collège favorable écrit directement).
     */
    private function reservation(array $data): Reservation
    {
        $organization = $this->orgs[$data['org']];
        $status = $data['status'];
        $date = $data['date'] ?? $this->weekday($data['days']);
        $destination = $this->places[$data['destination'] ?? 'seed-anseremme'];
        $requester = $this->users[$data['org']];
        $auto = in_array($organization->type, [OrganizationType::ECOLE_COMMUNALE, OrganizationType::PERSONNEL_COMMUNAL], true);
        $validated = in_array($status, [ReservationStatus::APPROUVE, ReservationStatus::CONFIRME], true);
        $cancelledBy = match ($data['cancelled_by'] ?? null) {
            null => null,
            'agent' => $this->agent->id,
            default => $this->users[$data['cancelled_by']]->id,
        };
        $distance = match ($destination->place_id) {
            'seed-givet' => [22.4, 30],
            'seed-anseremme' => [3.6, 10],
            default => [2.1, 8],
        };

        $reservation = Reservation::create([
            'requested_by_user_id' => $requester->id,
            'organization_id' => $organization->id,
            'pickup_location_id' => $this->places['seed-dinant-pickup']->id,
            'destination_location_id' => $destination->id,
            'driver_id' => isset($data['driver']) ? $this->drivers[$data['driver']]->id : null,
            'vehicle_id' => isset($data['vehicle']) ? $this->vehicles[$data['vehicle']]->id : null,
            'recurrence_id' => ($data['recurrence'] ?? null)?->id,
            'trip_date' => $date,
            'departure_time' => $data['departure'].':00',
            'return_time' => $data['return'].':00',
            'nb_children' => $data['children'] ?? 20,
            'nb_adults' => $data['adults'] ?? 2,
            'trip_purpose' => self::PREFIX.' '.$data['purpose'],
            'activity_type' => $data['activity'] ?? ReservationActivityType::STANDARD,
            'distance_km' => $distance[0],
            'estimated_duration_min' => $distance[1],
            'status' => $status,
            'approval_type' => $auto ? ApprovalType::AUTO : ApprovalType::MANUEL,
            'validated_by_user_id' => $validated ? $this->agent->id : null,
            'validated_at' => $validated ? CarbonImmutable::parse($date)->subDays(10) : null,
            'rejection_reason' => $data['rejection'] ?? null,
            'cancellation_reason' => $data['cancellation'] ?? null,
            'cancelled_by_user_id' => $cancelledBy,
            'cancelled_at' => $status === ReservationStatus::ANNULE ? CarbonImmutable::parse($date)->subDays(1) : null,
            'passengers_confirmed_at' => ($data['passengers_confirmed'] ?? true) && $validated ? CarbonImmutable::parse($date)->subDays(3) : null,
        ]);
        $reservation->forceFill(['created_at' => CarbonImmutable::parse($date)->subDays(25)->min(now())])->saveQuietly();

        if ($data['decision'] ?? false) {
            $this->recordDecisionDirectly($reservation, CouncilDecisionStatus::APPROUVE);
        }

        return $reservation;
    }

    /**
     * Jour à $offset jours d'aujourd'hui, ramené en semaine : un samedi ou un dimanche passe au lundi suivant (futur) ou
     * au vendredi précédent (passé). Aujourd'hui reste aujourd'hui.
     */
    private function weekday(int $offset): string
    {
        $day = $this->now->addDays($offset);
        if ($offset === 0 || ! $day->isWeekend()) {
            return $day->toDateString();
        }

        return ($offset > 0 ? $day->next(CarbonImmutable::MONDAY) : $day->previous(CarbonImmutable::FRIDAY))->toDateString();
    }

    private function conflict(Reservation $reservation, ReservationConflictType $type, string $message, ?Reservation $related = null): void
    {
        ReservationConflict::create([
            'reservation_id' => $reservation->id,
            'type' => $type,
            'message' => $message,
            'related_reservation_id' => $related?->id,
            'is_resolved' => false,
        ]);
    }

    /** Décision du Collège écrite directement (volume) : sans délibération PDF ni notification */
    private function recordDecisionDirectly(Reservation $reservation, CouncilDecisionStatus $status): void
    {
        DB::table('council_decisions')->insert([
            'reservation_id' => $reservation->id,
            'decision_status' => $status->value,
            'decision_date' => CarbonImmutable::parse($reservation->trip_date)->subDays(14)->toDateString(),
            'decision_reference' => 'DEMO-COL-V'.$reservation->id,
            'recorded_by_user_id' => $this->agent->id,
            'validated_at' => now(),
            'notes' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Étapes du chauffeur, dans l'ordre, à partir de $startMinutes (minutes avant maintenant, négatif), 15 min d'écart.
     *
     * @param  list<TripEventType>  $types
     */
    private function events(Reservation $reservation, array $types, int $startMinutes): void
    {
        $destination = Location::query()->findOrFail($reservation->destination_location_id);
        $pickup = $this->places['seed-dinant-pickup'];
        foreach ($types as $position => $type) {
            $place = in_array($type, [TripEventType::ARRIVEE_ALLER, TripEventType::DEPART_RETOUR], true) ? $destination : $pickup;
            $at = now()->addMinutes($startMinutes + $position * 15);
            TripEvent::create([
                'reservation_id' => $reservation->id,
                'driver_id' => $reservation->driver_id,
                'type' => $type,
                'occurred_at' => $at,
                'source' => TripEventSource::CHAUFFEUR,
                'position_state' => PositionState::ENVOYEE,
                'latitude' => (float) $place->latitude,
                'longitude' => (float) $place->longitude,
                'accuracy_m' => 15,
                'position_captured_at' => $at,
                'recorded_by_user_id' => Driver::query()->findOrFail($reservation->driver_id)->user_id,
            ]);
        }
    }

    private function report(Reservation $reservation, TripReportOutcome $outcome, ?string $remarks = null): void
    {
        $driver = Driver::query()->findOrFail($reservation->driver_id);
        $realised = $outcome === TripReportOutcome::REALISE;
        TripReport::create([
            'reservation_id' => $reservation->id,
            'driver_id' => $driver->id,
            'outcome' => $outcome,
            'actual_departure_time' => $realised ? substr((string) $reservation->getRawOriginal('departure_time'), 0, 5) : null,
            'actual_return_time' => $realised ? substr((string) $reservation->getRawOriginal('return_time'), 0, 5) : null,
            'actual_nb_children' => $realised ? $reservation->nb_children : null,
            'actual_nb_adults' => $realised ? $reservation->nb_adults : null,
            'remarks' => $realised ? null : ($remarks ?? 'Sortie annulée sur place (démo).'),
            'reported_by_user_id' => $driver->user_id,
            'reported_at' => CarbonImmutable::parse($reservation->trip_date)->setTimeFromTimeString('19:00')->min(now()),
        ]);
    }

    /** Plus petit PDF valide (justificatif de démo, accepté par ProofDocumentService) */
    private function tinyPdf(): string
    {
        return "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n"
            ."3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 200 200]>>endobj\n"
            ."xref\n0 4\n0000000000 65535 f \n0000000009 00000 n \n0000000052 00000 n \n0000000101 00000 n \n"
            ."trailer<</Size 4/Root 1 0 R>>\nstartxref\n164\n%%EOF\n";
    }
}
