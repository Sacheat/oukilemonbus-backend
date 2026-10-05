<?php

namespace Tests\Feature\Mail;

use App\Enums\ApprovalType;
use App\Enums\OrganizationType;
use App\Enums\ReservationStatus;
use App\Models\Document;
use App\Models\Driver;
use App\Models\Location;
use App\Models\Organization;
use App\Models\Reservation;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\Mail\EmailPresenter;
use App\Support\Mail\TripTicket;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TestFleetSeeder;
use Database\Seeders\TestLocationsSeeder;
use Database\Seeders\TestOrganizationsSeeder;
use Database\Seeders\TestUsersSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Logique de présentation des emails (EmailPresenter, TripTicket) : testée sans rendu Blade.
 * Trajet de test : mardi 16 juin 2026, 09:00 → 11:00, durée estimée 23 minutes.
 */
class EmailPresenterTest extends TestCase
{
    use DatabaseTransactions;

    private EmailPresenter $presenter;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->seed([
            RolesAndPermissionsSeeder::class,
            TestUsersSeeder::class,
            TestLocationsSeeder::class,
            TestOrganizationsSeeder::class,
            TestFleetSeeder::class,
        ]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        config(['app.frontend_url' => 'https://front.example.test']);
        $this->presenter = app(EmailPresenter::class);
    }

    public function test_tone_and_label_follow_the_event_key_then_the_category(): void
    {
        $cases = [
            ['reservation.rejected', 'reservation_updates', 'danger', 'Réservation refusée'],
            ['user_org_request.rejected', 'admin_workflow', 'danger', 'Demande refusée'],
            ['reservation.cancelled', 'last_minute_cancellation', 'danger', 'Réservation annulée'],
            ['reservation.confirmed', 'reservation_updates', 'success', 'Réservation confirmée'],
            ['reservation.confirmed', 'driver_planning', 'success', 'Mission confirmée'],
            ['reservation.approved', 'reservation_updates', 'success', 'Réservation approuvée'],
            ['org_registration_request.approved', 'admin_workflow', 'success', 'Demande approuvée'],
            ['reservation.passengers_confirmed', 'reservation_updates', 'info', 'Passagers confirmés'],
            ['reservation.confirmation_reminder', 'reservation_updates', 'info', 'Passagers à confirmer'],
            ['document.deliberation.generated', 'reservation_updates', 'info', 'Nouveau document'],
            ['reservation.conflict_detected', 'admin_workflow', 'conflict', 'Conflit'],
            ['reservation.conflict_resolved', 'reservation_updates', 'conflict', 'Conflit résolu'],
            ['trip_report.not_realised', 'admin_workflow', 'danger', 'Compte rendu du chauffeur'],
            ['org_registration_request.created', 'admin_workflow', 'info', 'Demande à traiter'],
            ['planning.export.ready', 'driver_planning', 'info', 'Export du planning'],
            ['reservation.expired', 'reservation_updates', 'neutral', 'Réservation clôturée'],
            ['reservation.something_else', 'reservation_updates', 'neutral', 'Réservation'],
            ['reservation.something_else', 'last_minute_cancellation', 'danger', 'Important'],
        ];

        foreach ($cases as [$eventKey, $category, $tone, $label]) {
            $result = $this->presenter->tone($eventKey, $category);
            $this->assertSame([$tone, $label], [$result['tone'], $result['label']], "{$eventKey} / {$category}");
        }

        // Les couleurs viennent de la charte.
        $this->assertSame(['bg' => '#fdecea', 'fg' => '#b42318'], $this->presenter->toneColors('danger'));
        $this->assertSame('#eaf6ef', $this->presenter->tone('reservation.confirmed', 'reservation_updates')['bg']);
    }

    public function test_spa_link_depends_on_the_recipient(): void
    {
        $reservation = $this->reservation();
        $id = $reservation->id;
        $url = fn (string $path): string => 'https://front.example.test'.$path;

        $agent = $this->user('agent.test@bus-communal.local');
        $admin = $this->user('admin.test@bus-communal.local');
        $this->assertSame(['url' => $url("/gestion/reservations?reservation={$id}"), 'label' => 'Ouvrir la réservation'], $this->presenter->link($agent, $reservation));
        $this->assertSame($url("/gestion/reservations?reservation={$id}"), $this->presenter->link($admin, $reservation)['url']);

        $assignedDriver = $this->user('chauffeur.test@bus-communal.local');
        $this->assertSame(['url' => $url("/chauffeur/missions/{$id}"), 'label' => 'Voir la mission'], $this->presenter->link($assignedDriver, $reservation));

        $requester = $this->user('user.test@bus-communal.local');
        $this->assertSame(['url' => $url("/espace/deplacements/{$id}"), 'label' => 'Voir le déplacement'], $this->presenter->link($requester, $reservation));

        // Un chauffeur qui n'est pas celui du trajet : pas de bouton, sauf s'il en est le demandeur.
        $otherDriver = User::factory()->create();
        $otherDriver->assignRole('chauffeur');
        $this->assertNull($this->presenter->link($otherDriver, $reservation));
        $own = $this->reservation(['requested_by_user_id' => $otherDriver->id]);
        $this->assertSame($url("/espace/deplacements/{$own->id}"), $this->presenter->link($otherDriver, $own)['url']);
    }

    public function test_explicit_link_is_used_without_a_reservation_and_nothing_without_either(): void
    {
        $agent = $this->user('agent.test@bus-communal.local');
        $explicit = ['path' => '/gestion/demandes?file=adhesions&statut=toutes&demande=7', 'label' => 'Ouvrir la demande'];

        $this->assertSame(
            ['url' => 'https://front.example.test/gestion/demandes?file=adhesions&statut=toutes&demande=7', 'label' => 'Ouvrir la demande'],
            $this->presenter->link($agent, null, $explicit)
        );
        $this->assertNull($this->presenter->link($agent, null, null));

        // Avec une réservation, c'est le lien de la réservation qui prime.
        $this->assertStringContainsString('/gestion/reservations?reservation=', $this->presenter->link($agent, $this->reservation(), $explicit)['url']);
    }

    public function test_document_link_label_and_attachment_flag(): void
    {
        $deliberation = new Document(['document_type' => 'deliberation', 'mime_type' => 'application/pdf']);

        $this->assertSame(
            ['url' => 'https://signed.test/doc', 'label' => 'Ouvrir la décision du Collège (PDF)', 'attached' => true],
            $this->presenter->documentLink($deliberation, 'https://signed.test/doc', true)
        );
        $this->assertSame('Ouvrir la feuille de route (PDF)', $this->presenter->documentLink(new Document(['document_type' => 'feuille_route', 'mime_type' => 'application/pdf']), 'https://x.test', false)['label']);
        $this->assertSame('Télécharger le fichier', $this->presenter->documentLink(new Document(['document_type' => 'planning', 'mime_type' => 'application/vnd.ms-excel']), 'https://x.test', false)['label']);
        $this->assertNull($this->presenter->documentLink($deliberation, null, false));
        $this->assertNull($this->presenter->documentLink(null, 'https://x.test', false));
    }

    public function test_message_loses_its_reason_and_numbers_are_humanized_for_the_email_only(): void
    {
        $this->assertSame(
            'Votre réservation n° 98 a été refusée.',
            $this->presenter->bodyMessage('Votre réservation #98 a été refusée. Motif: Aucun véhicule libre.', 'Aucun véhicule libre.')
        );
        $this->assertSame(
            "Le trajet n° 106 n'a pas été réalisé, selon Chloe.",
            $this->presenter->bodyMessage("Le trajet #106 n'a pas été réalisé, selon Chloe. Motif : école fermée", 'école fermée')
        );
        // Un motif qui n'est pas à la fin du message n'est pas touché ; sans motif, seul « # » change.
        $this->assertSame('La demande n° 7 est à revoir.', $this->presenter->bodyMessage('La demande #7 est à revoir.'));
        $this->assertSame('Texte Motif : autre', $this->presenter->bodyMessage('Texte Motif : autre', 'rien'));
    }

    public function test_subject_is_precise_for_key_events_and_the_title_otherwise(): void
    {
        $reservation = $this->reservation();
        $number = $reservation->id;
        $requester = $this->user('user.test@bus-communal.local');
        $driver = $this->user('chauffeur.test@bus-communal.local');
        $agent = $this->user('agent.test@bus-communal.local');

        $this->assertSame('Votre déplacement du 16 juin est confirmé', $this->presenter->subject('reservation.confirmed', 'Réservation confirmée', $reservation, $requester));
        $this->assertSame("Votre réservation n° {$number} est refusée", $this->presenter->subject('reservation.rejected', 'Réservation refusée', $reservation, $requester));
        $this->assertSame("Votre réservation n° {$number} est annulée", $this->presenter->subject('reservation.cancelled', 'Réservation annulée', $reservation, $requester));
        $this->assertSame("Réservation n° {$number} annulée", $this->presenter->subject('reservation.cancelled', 'Annulation de réservation', $reservation, $driver));
        $this->assertSame("Trajet non réalisé : réservation n° {$number}", $this->presenter->subject('trip_report.not_realised', 'Trajet non réalisé', $reservation, $agent));

        // Approuvée : « confirmé » seulement si la réservation l'est déjà.
        $this->assertSame('Votre déplacement du 16 juin est confirmé', $this->presenter->subject('reservation.approved', 'Réservation approuvée', $reservation, $requester));
        $reservation->update(['status' => ReservationStatus::APPROUVE]);
        $this->assertSame('Réservation approuvée', $this->presenter->subject('reservation.approved', 'Réservation approuvée', $reservation->fresh(), $requester));

        // Titres particuliers, chauffeur, événements sans objet dédié, réservation supprimée : le titre.
        $this->assertSame('Réservation de nouveau confirmée', $this->presenter->subject('reservation.confirmed', 'Réservation de nouveau confirmée', $reservation, $requester));
        $this->assertSame('Mission confirmée', $this->presenter->subject('reservation.confirmed', 'Mission confirmée', $reservation, $driver));
        $this->assertSame('Conflit : indisponibilité', $this->presenter->subject('reservation.conflict_detected', 'Conflit : indisponibilité', $reservation, $agent));
        $this->assertSame('Réservation refusée', $this->presenter->subject('reservation.rejected', 'Réservation refusée', null, $requester));
    }

    public function test_dedicated_copy_for_key_events(): void
    {
        $reservation = $this->reservation();
        $number = $reservation->id;
        $requester = $this->user('user.test@bus-communal.local');
        $driver = $this->user('chauffeur.test@bus-communal.local');

        $confirmed = $this->presenter->copy('reservation.confirmed', 'Réservation confirmée', 'Votre réservation #1 est confirmée.', null, $reservation, $requester);
        $this->assertSame('Votre déplacement est confirmé', $confirmed['title']);
        $this->assertStringStartsWith('Le bus communal vous attendra le jour prévu.', $confirmed['message']);

        $rejected = $this->presenter->copy('reservation.rejected', 'Réservation refusée', "Votre réservation #{$number} a été refusée. Motif: x", 'x', $reservation, $requester);
        $this->assertSame(['title' => 'Votre réservation est refusée', 'message' => "Votre demande de réservation n° {$number} n'a pas pu être acceptée."], $rejected);

        $cancelled = $this->presenter->copy('reservation.cancelled', 'Réservation annulée', 'x', 'y', $reservation, $requester);
        $this->assertSame(['title' => 'Votre réservation est annulée', 'message' => "La réservation n° {$number} a été annulée."], $cancelled);
        $this->assertSame('Réservation annulée', $this->presenter->copy('reservation.cancelled', 'Annulation de réservation', 'x', 'y', $reservation, $driver)['title']);

        // Le chauffeur garde le titre et le message de « Mission confirmée » ; un titre particulier aussi.
        $this->assertSame('Mission confirmée', $this->presenter->copy('reservation.confirmed', 'Mission confirmée', 'La mission #1 est confirmée.', null, $reservation, $driver)['title']);
        $again = $this->presenter->copy('reservation.confirmed', 'Réservation de nouveau confirmée', 'Votre réservation #1 est de nouveau confirmée.', null, $reservation, $requester);
        $this->assertSame(['title' => 'Réservation de nouveau confirmée', 'message' => 'Votre réservation n° 1 est de nouveau confirmée.'], $again);
    }

    public function test_complete_ticket_with_rounded_arrivals(): void
    {
        $ticket = TripTicket::for($this->reservation(['trip_date' => '2026-10-15']));

        $this->assertSame('Jeudi 15 octobre 2026', $ticket->dateLong);
        $this->assertSame('Ecole communale de Dinant', $ticket->organization);
        $this->assertSame([
            ['time' => '09:00', 'label' => 'Départ', 'place' => 'Rue du Centre 1, Dinant'],
            ['time' => '09:25', 'label' => 'Arrivée estimée', 'place' => 'Avenue des Sports 2, Dinant'],   // 09:23 arrondi à 09:25
            ['time' => '11:00', 'label' => 'Retour, arrivée vers 11:25', 'place' => 'Rue du Centre 1, Dinant'], // 11:23 arrondi à 11:25
        ], $ticket->legs);
        $this->assertSame(['11 passagers', 'Bus 1-VJX-649', 'Chloe C.'], $ticket->chips);
        $this->assertSame('Jeudi 15 octobre 2026 · 09:00 → 11:00', $ticket->summaryWhen);
        $this->assertSame('Rue du Centre 1, Dinant → Avenue des Sports 2, Dinant · Bus 1-VJX-649', $ticket->summaryRoute);
    }

    public function test_ticket_without_assignment_or_estimate(): void
    {
        $ticket = TripTicket::for($this->reservation([
            'driver_id' => null,
            'vehicle_id' => null,
            'estimated_duration_min' => null,
            'nb_children' => 0,
            'nb_adults' => 1,
            'status' => ReservationStatus::EN_ATTENTE,
        ]));

        $this->assertSame([
            ['time' => '09:00', 'label' => 'Départ', 'place' => 'Rue du Centre 1, Dinant'],
            ['time' => '11:00', 'label' => 'Retour', 'place' => 'Rue du Centre 1, Dinant'],
        ], $ticket->legs);
        $this->assertSame(['1 passager'], $ticket->chips);
        $this->assertSame('Mardi 16 juin 2026 · 09:00 → 11:00', $ticket->summaryWhen);
        $this->assertSame('Rue du Centre 1, Dinant → Avenue des Sports 2, Dinant', $ticket->summaryRoute);
    }

    public function test_rounding_follows_the_front_and_place_label_falls_back_to_the_address(): void
    {
        foreach ([['09:02', '09:00'], ['09:22', '09:20'], ['09:23', '09:25'], ['09:57', '09:55'], ['09:58', '10:00'], ['23:58', '00:00']] as [$time, $expected]) {
            $this->assertSame($expected, TripTicket::roundedTime(CarbonImmutable::parse("2026-06-16 {$time}")), $time);
        }

        $this->assertSame('Rue de Ciney 81, Ohey', TripTicket::place(new Location(['street' => 'Rue de Ciney', 'street_number' => '81', 'city' => 'Ohey', 'formatted_address' => 'ignorée'])));
        $this->assertSame('Dinant', TripTicket::place(new Location(['city' => 'Dinant', 'formatted_address' => 'ignorée'])));
        $this->assertSame('Place du Marché, Marche', TripTicket::place(new Location(['formatted_address' => 'Place du Marché, Marche'])));
        $this->assertSame('', TripTicket::place(null));
    }

    public function test_digest_data_puts_high_priority_first_and_uses_brussels_time(): void
    {
        $agent = $this->user('agent.test@bus-communal.local');
        $reservation = $this->reservation();
        $now = CarbonImmutable::parse('2026-10-03 10:00', 'Europe/Brussels');
        $items = [
            $this->digestItem('Conflit de réservation détecté', 'La réservation #1 a un conflit.', 'reservation.conflict_detected', $reservation->id, '2026-10-03T12:12:00Z'),
            $this->digestItem('Nouvelle demande', 'La demande #6 est à revoir.', 'org_registration_request.created', null, '2026-10-03T09:40:00Z', link: ['path' => '/gestion/demandes?demande=6', 'label' => 'Ouvrir la demande']),
            $this->digestItem('Trajet non réalisé', 'Le trajet #106 n’a pas été réalisé.', 'trip_report.not_realised', $reservation->id, '2026-10-03T07:05:00Z', priority: 'high', category: 'admin_workflow'),
        ];

        $digest = $this->presenter->digest($agent, $items, 'daily', CarbonImmutable::parse('2026-10-02 08:00', 'Europe/Brussels'), $now);

        $this->assertSame('Votre résumé du 3 octobre : 3 notifications', $digest['subject']);
        $this->assertSame('Résumé du samedi 3 octobre', $digest['surtitle']);
        $this->assertSame('3 nouvelles notifications', $digest['heading']);
        $this->assertSame('Bonjour Bruno, voici ce qui s’est passé depuis votre dernier résumé (hier, 08:00).', $digest['intro']);
        $this->assertSame(['Trajet non réalisé', 'Conflit de réservation détecté', 'Nouvelle demande'], array_column($digest['items'], 'title'));
        $this->assertSame(['09:05', '14:12', '11:40'], array_column($digest['items'], 'time')); // UTC+2 en octobre
        $this->assertSame(['#b42318', '#6454a0', '#1d4ed8'], array_column($digest['items'], 'dot'));
        $this->assertSame('Le trajet n° 106 n’a pas été réalisé.', $digest['items'][0]['message']);
        $this->assertSame('Ouvrir la réservation', $digest['items'][0]['link']['label']);
        $this->assertSame('https://front.example.test/gestion/demandes?demande=6', $digest['items'][2]['link']['url']);
        $this->assertSame(['url' => 'https://front.example.test/notifications', 'label' => 'Voir toutes mes notifications'], $digest['button']);
    }

    public function test_digest_subject_and_since_label_variants(): void
    {
        $user = $this->user('user.test@bus-communal.local');
        $now = CarbonImmutable::parse('2026-10-03 14:05', 'Europe/Brussels');
        $one = [$this->digestItem('Titre', 'Message', 'reservation.confirmed', null, '2026-10-03T12:00:00Z')];

        $hourly = $this->presenter->digest($user, $one, 'hourly', CarbonImmutable::parse('2026-10-03 13:05', 'Europe/Brussels'), $now);
        $this->assertSame('Votre résumé de 14:00 : 1 notification', $hourly['subject']);
        $this->assertSame('1 nouvelle notification', $hourly['heading']);
        $this->assertStringContainsString('(aujourd’hui, 13:05)', $hourly['intro']);

        $older = $this->presenter->digest($user, $one, 'daily', CarbonImmutable::parse('2026-09-28 08:00', 'Europe/Brussels'), $now);
        $this->assertStringContainsString('(le 28 septembre, 08:00)', $older['intro']);

        $first = $this->presenter->digest($user, $one, 'none', null, $now);
        $this->assertSame('Votre résumé du 3 octobre : 1 notification', $first['subject']);
        $this->assertStringEndsWith('depuis votre dernier résumé.', $first['intro']);
    }

    /** @param array<string, mixed> $overrides */
    private function reservation(array $overrides = []): Reservation
    {
        return Reservation::create([
            'requested_by_user_id' => $this->user('user.test@bus-communal.local')->id,
            'organization_id' => Organization::query()->where('type', OrganizationType::ECOLE_COMMUNALE)->orderBy('id')->firstOrFail()->id,
            'pickup_location_id' => Location::query()->where('place_id', 'seed-dinant-pickup')->firstOrFail()->id,
            'destination_location_id' => Location::query()->where('place_id', 'seed-dinant-destination')->firstOrFail()->id,
            'driver_id' => Driver::query()->where('licence_number', 'DRV-SEED-001')->firstOrFail()->id,
            'vehicle_id' => Vehicle::query()->where('licence_plate', '1-VJX-649')->firstOrFail()->id,
            'trip_date' => '2026-06-16',
            'departure_time' => '09:00:00',
            'return_time' => '11:00:00',
            'nb_children' => 10,
            'nb_adults' => 1,
            'trip_purpose' => 'Sortie',
            'estimated_duration_min' => 23,
            'status' => ReservationStatus::CONFIRME,
            'approval_type' => ApprovalType::AUTO,
            ...$overrides,
        ]);
    }

    /** @return array<string, mixed> */
    private function digestItem(string $title, string $message, string $eventKey, ?int $reservationId, string $createdAt, string $priority = 'normal', string $category = 'admin_workflow', ?array $link = null): array
    {
        return [
            'title' => $title,
            'message' => $message,
            'category' => $category,
            'priority' => $priority,
            'event_key' => $eventKey,
            'reservation_id' => $reservationId,
            'link' => $link,
            'created_at' => $createdAt,
        ];
    }

    private function user(string $email): User
    {
        return User::query()->where('email', $email)->firstOrFail();
    }
}
