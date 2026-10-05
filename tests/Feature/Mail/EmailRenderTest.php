<?php

namespace Tests\Feature\Mail;

use App\Enums\ApprovalType;
use App\Enums\DocumentType;
use App\Enums\OrganizationType;
use App\Enums\ReservationStatus;
use App\Jobs\SendUserDigestJob;
use App\Models\Document;
use App\Models\Driver;
use App\Models\Location;
use App\Models\NotificationDigestQueue;
use App\Models\Organization;
use App\Models\Reservation;
use App\Models\User;
use App\Models\Vehicle;
use App\Notifications\Auth\QueuedResetPassword;
use App\Notifications\Auth\QueuedVerifyEmail;
use App\Notifications\OperationalDigestNotification;
use App\Notifications\OperationalNotification;
use App\Services\Notifications\NotificationDispatchService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TestFleetSeeder;
use Database\Seeders\TestLocationsSeeder;
use Database\Seeders\TestOrganizationsSeeder;
use Database\Seeders\TestUsersSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

/**
 * Rendu des gabarits Blade des emails (notification, récapitulatif, vérification, mot de passe oublié), canal
 * `database` inchangé, et pipeline complet jusqu'au transport mail. Trajet de test : mardi 16 juin 2026, 09:00 → 11:00.
 */
class EmailRenderTest extends TestCase
{
    use DatabaseTransactions;

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
    }

    public function test_user_notification_has_the_complete_ticket_and_a_spa_button(): void
    {
        $reservation = $this->reservation();
        $user = $this->user('user.test@bus-communal.local');

        $mail = $this->notification('reservation.confirmed', 'reservation_updates', 'Réservation confirmée', "Votre réservation #{$reservation->id} est confirmée.", reservationId: $reservation->id)
            ->toMail($user);
        $html = $this->html($mail);

        $this->assertSame('Votre déplacement du 16 juin est confirmé', $mail->subject);
        foreach ([
            'Bonjour David,',
            'Réservation confirmée',               // pastille
            'Votre déplacement est confirmé',      // titre dédié
            "Réservation n° {$reservation->id}",
            'Mardi 16 juin 2026',
            'Arrivée estimée',
            '11 passagers', 'Bus 1-VJX-649', 'Chloe C.',
            'Voir le déplacement',
            "https://front.example.test/espace/deplacements/{$reservation->id}",
            'Si le bouton ne fonctionne pas',
            'Service du bus communal · Commune de Dinant',
            'Cet email est envoyé automatiquement, merci de ne pas y répondre.',
        ] as $expected) {
            $this->assertStringContainsString($expected, $html, $expected);
        }
        $this->assertStringNotContainsString('À traiter rapidement', $html);
        $this->assertStringNotContainsString('Gestion', $html);
        $this->assertSame("https://front.example.test/espace/deplacements/{$reservation->id}", $mail->actionUrl);

        $text = $this->text($mail);
        $this->assertStringContainsString('Bonjour David,', $text);
        $this->assertStringContainsString("Voir le déplacement : https://front.example.test/espace/deplacements/{$reservation->id}", $text);
        $this->assertStringNotContainsString('<', $text);
    }

    public function test_agent_notification_with_high_priority_shows_banner_reason_quote_and_summary(): void
    {
        $reservation = $this->reservation(['trip_date' => '2026-10-02']);
        $agent = $this->user('agent.test@bus-communal.local');
        $message = "Le trajet #{$reservation->id} du 02/10/2026 (Ecole communale de Dinant) n'a pas été réalisé, selon Chloe Chauffeur. Motif : école fermée ce jour-là";

        $mail = $this->notification('trip_report.not_realised', 'admin_workflow', 'Trajet non réalisé', $message, priority: 'high', reservationId: $reservation->id, reason: 'école fermée ce jour-là')
            ->toMail($agent);
        $html = $this->html($mail);

        $this->assertSame("Trajet non réalisé : réservation n° {$reservation->id}", $mail->subject);
        $this->assertStringContainsString('Gestion', $html);
        $this->assertStringContainsString('À traiter rapidement', $html);
        $this->assertStringContainsString('Compte rendu du chauffeur', $html);
        $this->assertStringContainsString('Bonjour Bruno,', $html);
        $this->assertStringContainsString("https://front.example.test/gestion/reservations?reservation={$reservation->id}", $html);
        $this->assertStringContainsString('Ouvrir la réservation', $html);
        // Résumé (pas de billet complet) et motif affiché une seule fois, en citation.
        $this->assertStringContainsString('Vendredi 2 octobre 2026 · 09:00 → 11:00', $html);
        $this->assertStringNotContainsString('Arrivée estimée', $html);
        $this->assertSame(1, substr_count($html, 'école fermée ce jour-là'));
        $this->assertStringContainsString('Motif :', $html);
        $this->assertStringContainsString('selon Chloe Chauffeur.</p>', $html);
    }

    public function test_the_chauffeur_of_the_trip_gets_the_mission_link_and_the_complete_ticket(): void
    {
        $reservation = $this->reservation();
        $driver = $this->user('chauffeur.test@bus-communal.local');

        $mail = $this->notification('reservation.cancelled', 'last_minute_cancellation', 'Annulation de réservation', "La réservation #{$reservation->id} a été annulée. Motif: Sortie annulée.", priority: 'high', reservationId: $reservation->id, reason: 'Sortie annulée.')
            ->toMail($driver);
        $html = $this->html($mail);

        $this->assertSame("Réservation n° {$reservation->id} annulée", $mail->subject);
        $this->assertStringContainsString("https://front.example.test/chauffeur/missions/{$reservation->id}", $html);
        $this->assertStringContainsString('Voir la mission', $html);
        $this->assertStringContainsString('Départ', $html);
        $this->assertStringContainsString("n'avez plus à assurer cette mission", str_replace('&#039;', "'", $html));
    }

    public function test_without_reservation_the_link_or_the_document_decides_the_button(): void
    {
        $agent = $this->user('agent.test@bus-communal.local');

        // Lien explicite (demande) : bouton vers la SPA, pas de billet.
        $request = $this->notification('user_org_request.created', 'admin_workflow', 'Nouvelle demande de rattachement organisation', 'La demande #7 doit être revue par un agent communal.', link: ['path' => '/gestion/demandes?file=adhesions&statut=toutes&demande=7', 'label' => 'Ouvrir la demande'])
            ->toMail($agent);
        $this->assertStringContainsString('La demande n° 7 doit être revue par un agent communal.', $this->html($request));
        $this->assertStringContainsString('href="https://front.example.test/gestion/demandes?file=adhesions&amp;statut=toutes&amp;demande=7"', $this->html($request));
        $this->assertStringContainsString('Ouvrir la demande', $this->html($request));
        $this->assertStringNotContainsString('Réservation n°', $this->html($request));

        // Document seul : « Ouvrir le document » (lien signé).
        $export = $this->notification('planning.export.ready', 'driver_planning', 'Export planning disponible', 'Votre export est prêt.', actionUrl: 'https://signed.example.test/doc', document: $this->document())
            ->toMail($this->user('user.test@bus-communal.local'));
        $this->assertStringContainsString('Ouvrir le document', $this->html($export));
        $this->assertSame('https://signed.example.test/doc', $export->actionUrl);

        // Document non PDF (export Excel) : il est téléchargé, le bouton le dit.
        $xls = $this->document();
        $xls->update(['file_name' => 'planning.xls', 'mime_type' => 'application/vnd.ms-excel']);
        $download = $this->notification('planning.export.ready', 'driver_planning', 'Export planning disponible', 'Votre export est prêt.', actionUrl: 'https://signed.example.test/xls', document: $xls)
            ->toMail($this->user('user.test@bus-communal.local'));
        $this->assertStringContainsString('Télécharger le fichier', $this->html($download));
        $this->assertStringNotContainsString('Ouvrir le document', $this->html($download));

        // Rien à ouvrir : pas de bouton ni de lien de secours.
        $plain = $this->html($this->notification('reservation.expired.summary', 'admin_workflow', 'Récapitulatif', '3 réservations clôturées.')->toMail($agent));
        $this->assertStringNotContainsString('class="btn"', $plain);
        $this->assertStringNotContainsString('Si le bouton ne fonctionne pas', $plain);

        // Réservation supprimée depuis l'envoi : ni billet ni bouton.
        $gone = $this->html($this->notification('reservation.confirmed', 'reservation_updates', 'Réservation confirmée', 'Votre réservation #999999 est confirmée.', reservationId: 999999)->toMail($this->user('user.test@bus-communal.local')));
        $this->assertStringNotContainsString('class="btn"', $gone);
        $this->assertStringNotContainsString('Réservation n° 999999 ·', $gone);
    }

    public function test_document_secondary_link_and_attachment_follow_the_size_limit(): void
    {
        Storage::fake('local');
        $reservation = $this->reservation();
        $user = $this->user('user.test@bus-communal.local');
        $document = $this->document();
        Storage::disk('local')->put($document->file_path, '%PDF-1.4 contenu');

        $attached = $this->notification('document.deliberation.generated', 'reservation_updates', 'Délibération disponible', 'La délibération est disponible.', reservationId: $reservation->id, actionUrl: 'https://signed.example.test/doc', document: $document, attach: true)
            ->toMail($user);
        $this->assertCount(1, $attached->attachments);
        $this->assertStringContainsString('Ouvrir la décision du Collège (PDF)', $this->html($attached));
        $this->assertStringContainsString('aussi jointe à cet email', $this->html($attached));
        $this->assertStringContainsString('Voir le déplacement', $this->html($attached));
        $this->assertSame("https://front.example.test/espace/deplacements/{$reservation->id}", $attached->actionUrl);

        // Au-delà de la limite : pas de pièce jointe, le lien signé reste.
        config(['notifications.mail.max_attachment_bytes' => 5]);
        $tooBig = $this->notification('document.deliberation.generated', 'reservation_updates', 'Délibération disponible', 'La délibération est disponible.', reservationId: $reservation->id, actionUrl: 'https://signed.example.test/doc', document: $document, attach: true)
            ->toMail($user);
        $this->assertCount(0, $tooBig->attachments);
        $this->assertStringContainsString('Ouvrir la décision du Collège (PDF)', $this->html($tooBig));
        $this->assertStringNotContainsString('aussi jointe', $this->html($tooBig));
    }

    public function test_the_layout_is_responsive_and_safe_for_mail_clients(): void
    {
        $html = $this->html($this->notification('reservation.confirmed', 'reservation_updates', 'Réservation confirmée', 'ok', reservationId: $this->reservation()->id)->toMail($this->user('user.test@bus-communal.local')));

        foreach ([
            'name="viewport" content="width=device-width, initial-scale=1"',
            'name="x-apple-disable-message-reformatting"',
            'name="color-scheme" content="light"',
            'name="supported-color-schemes" content="light"',
            'width="600"', 'max-width:600px',
            '@media only screen and (max-width: 480px)',
            '.btn { display: block !important',
            'overflow-wrap:anywhere',
            'alt="OuKiLéMonBus"', 'width="32" height="32"',
            'font-family:Inter,&#039;Segoe UI&#039;,Arial,sans-serif',
        ] as $expected) {
            $this->assertStringContainsString($expected, str_replace("'", '&#039;', $html), $expected);
        }
        $this->assertStringNotContainsString('<svg', $html);
        $this->assertStringNotContainsString('<script', $html);
        $this->assertSame(1, substr_count($html, '<style>'), 'Un seul bloc <style>, réservé aux media queries.');
    }

    public function test_database_channel_is_unchanged(): void
    {
        $user = $this->user('user.test@bus-communal.local');
        $message = 'Votre réservation #98 a été refusée. Motif: Aucun véhicule libre.';

        $legacy = (new OperationalNotification('reservation.rejected', 'reservation_updates', 'Réservation refusée', $message, 'normal', 98, 'https://signed.example.test/doc', null, false, ['database']))->toArray($user);
        $withEmailParameters = (new OperationalNotification('reservation.rejected', 'reservation_updates', 'Réservation refusée', $message, 'normal', 98, 'https://signed.example.test/doc', null, false, ['database'], 'Aucun véhicule libre.', ['path' => '/espace/organisations', 'label' => 'x']))->toArray($user);

        unset($legacy['created_at'], $withEmailParameters['created_at']);
        $this->assertSame($legacy, $withEmailParameters);
        $this->assertSame([
            'event_key' => 'reservation.rejected',
            'category' => 'reservation_updates',
            'priority' => 'normal',
            'title' => 'Réservation refusée',
            'message' => $message, // motif compris, « #98 » inchangé : la cloche du front ne bouge pas
            'reservation_id' => 98,
            'document_id' => null,
            'action_url' => 'https://signed.example.test/doc', // lien signé du document, jamais le lien SPA
        ], $withEmailParameters);
    }

    public function test_dispatch_sends_the_new_email_and_keeps_the_stored_notification(): void
    {
        $reservation = $this->reservation(['trip_date' => '2026-12-22']);
        $user = $this->user('user.test@bus-communal.local');
        $message = "Votre réservation #{$reservation->id} a été refusée. Motif: Aucun véhicule libre.";

        app(NotificationDispatchService::class)->dispatch(
            users: collect([$user]),
            eventKey: 'reservation.rejected',
            category: 'reservation_updates',
            title: 'Réservation refusée',
            message: $message,
            reservation: $reservation,
            urgent: true,
            reason: 'Aucun véhicule libre.'
        );

        // Cloche : même message qu'avant (motif compris, « # »), pas de lien SPA.
        $stored = $user->notifications()->latest()->first();
        $this->assertSame($message, $stored->data['message']);
        $this->assertNull($stored->data['action_url']);
        $this->assertSame($reservation->id, $stored->data['reservation_id']);

        // Email : réellement envoyé (file synchrone en test) avec le gabarit.
        $transportMessages = $this->sentEmails();
        $this->assertCount(1, $transportMessages);
        $email = $transportMessages[0];
        $this->assertSame("Votre réservation n° {$reservation->id} est refusée", $email->getSubject());
        $this->assertStringContainsString('Aucun véhicule libre.', (string) $email->getHtmlBody());
        $this->assertStringContainsString('Motif : Aucun véhicule libre.', (string) $email->getTextBody());
        $this->assertSame(1, substr_count((string) $email->getHtmlBody(), 'Aucun véhicule libre.'));
        $this->assertStringContainsString('cid:', (string) $email->getHtmlBody(), 'Le logo est une pièce jointe en ligne.');
        $contentIds = array_map(fn ($part) => $part->getContentId(), array_filter($email->getAttachments(), fn ($part) => $part->hasContentId()));
        $this->assertCount(1, $contentIds);
        $this->assertStringContainsString('cid:'.reset($contentIds), (string) $email->getHtmlBody());
    }

    public function test_request_notifications_carry_a_real_number_the_link_and_the_reason(): void
    {
        $organization = Organization::query()->firstOrFail();
        $requester = User::factory()->create(['first_name' => 'Camille']);
        $requester->assignRole('user');

        Sanctum::actingAs($requester);
        $created = $this->postJson('/api/user-org-requests', ['organization_id' => $organization->id, 'requested_role' => 'prof', 'justification' => 'Je suis professeur dans cette école.'])
            ->assertCreated();
        $id = $created->json('data.request_id');

        $agentMail = collect($this->sentEmails())->first(fn (Email $email) => str_contains((string) $email->getSubject(), 'Nouvelle demande de rattachement organisation'));
        $this->assertNotNull($agentMail);
        $this->assertStringContainsString("La demande n° {$id} doit être revue par un agent communal.", (string) $agentMail->getHtmlBody());
        $this->assertStringContainsString("demande={$id}", html_entity_decode((string) $agentMail->getHtmlBody()));
        $this->assertStringContainsString('/gestion/demandes?file=adhesions&statut=toutes', html_entity_decode((string) $agentMail->getHtmlBody()));

        Sanctum::actingAs($this->user('agent.test@bus-communal.local'));
        $this->patchJson("/api/user-org-requests/{$id}/reject", ['rejection_reason' => 'Justificatif manquant.'])->assertOk();

        $refusal = collect($this->sentEmails())->first(fn (Email $email) => $email->getSubject() === 'Adhésion refusée' && array_key_exists($requester->email, $this->recipients($email)));
        $this->assertNotNull($refusal);
        $html = (string) $refusal->getHtmlBody();
        $this->assertStringContainsString('Bonjour Camille,', $html);
        $this->assertStringContainsString('Motif :', $html);
        $this->assertSame(1, substr_count($html, 'Justificatif manquant.'));
        $this->assertStringContainsString('https://front.example.test/espace/organisations', $html);
        $this->assertStringContainsString('Voir mes organisations', $html);
    }

    public function test_cancellation_refusal_and_unrealised_trip_emails_quote_their_reason(): void
    {
        $agent = $this->user('agent.test@bus-communal.local');
        $requester = $this->user('user.test@bus-communal.local');

        // Annulation par un agent : le demandeur et le chauffeur reçoivent chacun leur email.
        $cancelled = $this->reservation(['trip_date' => '2026-12-22']);
        Sanctum::actingAs($agent);
        $this->patchJson("/api/reservations/{$cancelled->id}/cancel", ['reason' => 'Bus en panne.'])->assertOk();

        $toRequester = $this->emailTo($requester->email, "Votre réservation n° {$cancelled->id} est annulée");
        $toDriver = $this->emailTo('chauffeur.test@bus-communal.local', "Réservation n° {$cancelled->id} annulée");
        foreach ([$toRequester, $toDriver] as $email) {
            $this->assertSame(1, substr_count((string) $email->getHtmlBody(), 'Bus en panne.'));
            $this->assertStringContainsString('Motif :', (string) $email->getHtmlBody());
        }
        $this->assertStringContainsString('Votre réservation est annulée', (string) $toRequester->getHtmlBody());
        $this->assertStringContainsString("/espace/deplacements/{$cancelled->id}", (string) $toRequester->getHtmlBody());
        $this->assertStringContainsString("/chauffeur/missions/{$cancelled->id}", (string) $toDriver->getHtmlBody());

        // Refus.
        $pending = $this->reservation(['trip_date' => '2026-12-23', 'driver_id' => null, 'vehicle_id' => null, 'status' => ReservationStatus::EN_ATTENTE]);
        $this->patchJson("/api/reservations/{$pending->id}/reject", ['reason' => 'Aucun véhicule libre.'])->assertOk();
        $refusal = $this->emailTo($requester->email, "Votre réservation n° {$pending->id} est refusée");
        $this->assertStringContainsString('Votre demande de réservation n° '.$pending->id.' n', (string) $refusal->getHtmlBody());
        $this->assertSame(1, substr_count((string) $refusal->getHtmlBody(), 'Aucun véhicule libre.'));

        // Trajet non réalisé : les agents et admins (sauf l'auteur) reçoivent le bandeau et la citation.
        $past = $this->reservation(['trip_date' => '2026-06-16']);
        $this->putJson("/api/reservations/{$past->id}/trip-report", ['outcome' => 'non_realise', 'remarks' => 'École fermée ce jour-là.'])->assertSuccessful();
        $unrealised = $this->emailTo('admin.test@bus-communal.local', "Trajet non réalisé : réservation n° {$past->id}");
        $html = (string) $unrealised->getHtmlBody();
        $this->assertStringContainsString('À traiter rapidement', $html);
        $this->assertSame(1, substr_count($html, 'École fermée ce jour-là.'));
        $this->assertStringContainsString('Gestion', $html);
        $this->assertNull(collect($this->sentEmails())->first(fn (Email $email) => array_key_exists($agent->email, $this->recipients($email)) && str_contains((string) $email->getSubject(), 'Trajet non réalisé')));
    }

    public function test_digest_email_lists_the_notifications_with_links_and_a_text_version(): void
    {
        $agent = $this->user('agent.test@bus-communal.local');
        $reservation = $this->reservation();
        $queue = fn (array $attributes) => NotificationDigestQueue::create([
            'user_id' => $agent->id,
            'category' => 'admin_workflow',
            'priority' => 'normal',
            'available_at' => now()->subMinute(),
            ...$attributes,
        ]);
        $queue(['event_key' => 'reservation.conflict_detected', 'title' => 'Conflit de réservation détecté', 'message' => "La réservation #{$reservation->id} a été créée avec un conflit.", 'reservation_id' => $reservation->id]);
        $queue(['event_key' => 'org_registration_request.created', 'title' => 'Nouvelle demande de création d’organisation', 'message' => 'La demande #6 doit être revue par un agent communal.', 'payload' => ['link' => ['path' => '/gestion/demandes?file=organisations&statut=toutes&demande=6', 'label' => 'Ouvrir la demande']]]);
        $queue(['event_key' => 'trip_report.not_realised', 'title' => 'Trajet non réalisé', 'message' => 'Le trajet #106 n’a pas été réalisé.', 'priority' => 'high', 'reservation_id' => $reservation->id]);

        Notification::fake();
        (new SendUserDigestJob($agent->id))->handle();

        $digest = Notification::sent($agent, OperationalDigestNotification::class)->first();
        $this->assertNotNull($digest);
        $mail = $digest->toMail($agent);
        $html = $this->html($mail);

        $this->assertStringContainsString(' : 3 notifications', $mail->subject);
        $this->assertStringStartsWith('Votre résumé du ', $mail->subject);
        $this->assertStringContainsString('3 nouvelles notifications', $html);
        $this->assertStringContainsString('Bonjour Bruno,', $html);
        $this->assertStringContainsString('Voir toutes mes notifications', $html);
        $this->assertStringContainsString('https://front.example.test/notifications', $html);
        $this->assertStringContainsString('Ouvrir la demande', $html);
        $this->assertStringContainsString('Ouvrir la réservation', $html);
        $this->assertStringContainsString('La demande n° 6 doit être revue', $html);
        // Priorité haute en tête.
        $this->assertLessThan(strpos($html, 'Conflit de réservation détecté'), strpos($html, 'Trajet non réalisé'));
        $this->assertStringContainsString('- Trajet non réalisé', $this->text($mail));
        $this->assertStringContainsString('https://front.example.test/notifications', $this->text($mail));
    }

    public function test_verification_and_password_reset_emails(): void
    {
        $user = $this->user('user.test@bus-communal.local');
        config(['auth.verification.expire' => 45, 'auth.passwords.users.expire' => 30]);

        $verify = (new QueuedVerifyEmail)->toMail($user);
        $html = $this->html($verify);
        $this->assertSame('Confirmez votre adresse email', $verify->subject);
        foreach (['Confirmez votre adresse email', 'Bonjour David,', 'Bienvenue sur OuKiLéMonBus', 'bus communal de Dinant', 'Confirmer mon adresse', '<strong>45 minutes</strong>', 'Vous n’avez pas créé de compte ?', 'Si le bouton ne fonctionne pas'] as $expected) {
            $this->assertStringContainsString($expected, $html, $expected);
        }
        $this->assertStringStartsWith('https://front.example.test/verify-email?id='.$user->id, $verify->actionUrl);
        $this->assertStringContainsString($verify->actionUrl, $this->text($verify));
        $this->assertStringContainsString('Un compte a été créé pour vous', $this->html((new QueuedVerifyEmail(createdByAdmin: true))->toMail($user)));
        $this->assertStringNotContainsString('Bienvenue', $this->html((new QueuedVerifyEmail(createdByAdmin: true))->toMail($user)));

        $reset = (new QueuedResetPassword('jeton-123'))->toMail($user);
        $resetHtml = $this->html($reset);
        $this->assertSame('Réinitialisez votre mot de passe', $reset->subject);
        foreach (['Réinitialisez votre mot de passe', 'Bonjour David,', 'Vous avez demandé à changer le mot de passe', 'Choisir un nouveau mot de passe', '<strong>30 minutes</strong>', 'votre mot de passe reste inchangé'] as $expected) {
            $this->assertStringContainsString($expected, $resetHtml, $expected);
        }
        $this->assertStringStartsWith('https://front.example.test/reset-password?token=jeton-123&email=', $reset->actionUrl);
        $this->assertStringContainsString('Un administrateur vous a envoyé ce lien', $this->html((new QueuedResetPassword('jeton-123', sentByAdmin: true))->toMail($user)));
        $this->assertStringContainsString($reset->actionUrl, $this->text($reset));
    }

    public function test_admin_created_account_and_admin_reset_link_use_their_own_wording(): void
    {
        Notification::fake();
        Sanctum::actingAs($this->user('admin.test@bus-communal.local'));

        $created = $this->postJson('/api/users', ['first_name' => 'Nouvelle', 'last_name' => 'Personne', 'email' => 'nouvelle@example.test', 'password' => 'Password123!', 'password_confirmation' => 'Password123!'])->assertCreated();
        $newUser = User::findOrFail($created->json('data.id'));
        $verification = Notification::sent($newUser, QueuedVerifyEmail::class)->first();
        $this->assertStringContainsString('Un compte a été créé pour vous', $this->html($verification->toMail($newUser)));

        $target = $this->user('user.test@bus-communal.local');
        $this->postJson("/api/users/{$target->id}/password-reset-link")->assertAccepted();
        $reset = Notification::sent($target, QueuedResetPassword::class)->first();
        $this->assertStringContainsString('Un administrateur vous a envoyé ce lien', $this->html($reset->toMail($target)));

        // « Mot de passe oublié » et inscription gardent le texte générique.
        $this->app['auth']->forgetGuards();
        Notification::fake();
        $this->postJson('/api/auth/forgot-password', ['email' => 'agent.test@bus-communal.local'])->assertOk();
        $agent = $this->user('agent.test@bus-communal.local');
        $this->assertStringContainsString('Vous avez demandé à changer', $this->html(Notification::sent($agent, QueuedResetPassword::class)->first()->toMail($agent)));
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

    private function notification(
        string $eventKey,
        string $category,
        string $title,
        string $message,
        string $priority = 'normal',
        ?int $reservationId = null,
        ?string $actionUrl = null,
        ?Document $document = null,
        bool $attach = false,
        ?string $reason = null,
        ?array $link = null
    ): OperationalNotification {
        return new OperationalNotification($eventKey, $category, $title, $message, $priority, $reservationId, $actionUrl, $document, $attach, ['mail'], $reason, $link);
    }

    private function document(): Document
    {
        return Document::create([
            'document_type' => DocumentType::DELIBERATION,
            'generation_mode' => 'auto',
            'file_name' => 'deliberation.pdf',
            'file_path' => 'documents/test/deliberation.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 16,
            'checksum' => hash('sha256', 'deliberation'),
            'is_available' => true,
        ]);
    }

    private function html(MailMessage $mail): string
    {
        return (string) $mail->render();
    }

    private function text(MailMessage $mail): string
    {
        return view($mail->view[1], $mail->data())->render();
    }

    /** @return list<Email> emails passés au transport « array » (MAIL_MAILER=array en test) */
    private function sentEmails(): array
    {
        return collect(app('mail.manager')->mailer('array')->getSymfonyTransport()->messages())
            ->map(fn ($sent) => $sent->getOriginalMessage())
            ->all();
    }

    private function emailTo(string $address, string $subject): Email
    {
        $email = collect($this->sentEmails())->first(fn (Email $email) => $email->getSubject() === $subject && array_key_exists($address, $this->recipients($email)));
        $this->assertNotNull($email, "Aucun email « {$subject} » pour {$address}.");

        return $email;
    }

    /** @return array<string, string> */
    private function recipients(Email $email): array
    {
        $recipients = [];
        foreach ($email->getTo() as $address) {
            $recipients[$address->getAddress()] = $address->getName();
        }

        return $recipients;
    }

    private function user(string $email): User
    {
        return User::query()->where('email', $email)->firstOrFail();
    }
}
