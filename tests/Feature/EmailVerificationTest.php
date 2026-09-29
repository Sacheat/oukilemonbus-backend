<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\Auth\QueuedVerifyEmail;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Queue;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

class EmailVerificationTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.frontend_url' => 'https://front.example.test',
            'app.frontend_email_verification_path' => '/verify-email',
        ]);
    }

    public function test_verification_email_links_to_the_frontend_with_signed_parameters(): void
    {
        $user = User::factory()->unverified()->create();

        $query = $this->verificationQuery($user);

        $this->assertSame((string) $user->id, $query['id']);
        $this->assertSame(sha1($user->email), $query['hash']);
        $this->assertArrayHasKey('expires', $query);
        $this->assertArrayHasKey('signature', $query);
    }

    public function test_frontend_parameters_verify_the_email_through_the_api(): void
    {
        $user = User::factory()->unverified()->create();
        $query = $this->verificationQuery($user);

        $this->getJson("/api/email/verify/{$query['id']}/{$query['hash']}?expires={$query['expires']}&signature={$query['signature']}")
            ->assertOk()
            ->assertJsonPath('message', 'Adresse email vérifiée avec succès.');

        $this->assertTrue($user->fresh()->hasVerifiedEmail());
    }

    public function test_verification_rejects_a_tampered_signature(): void
    {
        $user = User::factory()->unverified()->create();
        $query = $this->verificationQuery($user);

        $this->getJson("/api/email/verify/{$query['id']}/{$query['hash']}?expires={$query['expires']}&signature=invalid")
            ->assertForbidden()
            ->assertExactJson([
                'message' => 'Ce lien de vérification est invalide ou a expiré. Demandez un nouvel e-mail de vérification.',
            ]);

        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    public function test_expired_link_returns_the_french_message(): void
    {
        $user = User::factory()->unverified()->create();
        $query = $this->verificationQuery($user);

        $this->travel(61)->minutes();

        $this->getJson("/api/email/verify/{$query['id']}/{$query['hash']}?expires={$query['expires']}&signature={$query['signature']}")
            ->assertForbidden()
            ->assertJsonPath('message', 'Ce lien de vérification est invalide ou a expiré. Demandez un nouvel e-mail de vérification.');
    }

    public function test_verification_email_is_queued_so_registration_does_not_depend_on_smtp(): void
    {
        // Avec une vraie file, la requête HTTP ne fait que pousser le job : un SMTP en échec ne peut plus la faire échouer.
        Queue::fake();

        $this->postJson('/api/auth/register', [
            'first_name' => 'Alice',
            'last_name' => 'Martin',
            'email' => 'file@example.test',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ])->assertCreated();

        Queue::assertPushed(SendQueuedNotifications::class, fn (SendQueuedNotifications $job): bool => $job->notification instanceof QueuedVerifyEmail);
        $this->assertInstanceOf(ShouldQueue::class, new QueuedVerifyEmail);
    }

    public function test_resend_queues_the_verification_email(): void
    {
        Queue::fake();
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)
            ->postJson('/api/email/verify/resend')
            ->assertOk()
            ->assertJsonPath('message', 'Email de vérification envoyé.');

        Queue::assertPushed(SendQueuedNotifications::class, fn (SendQueuedNotifications $job): bool => $job->notification instanceof QueuedVerifyEmail);
    }

    private function verificationQuery(User $user): array
    {
        Notification::fake();
        $user->sendEmailVerificationNotification();

        $url = null;
        Notification::assertSentTo($user, QueuedVerifyEmail::class, function (QueuedVerifyEmail $notification) use ($user, &$url): bool {
            $url = $notification->toMail($user)->actionUrl;

            return true;
        });

        $this->assertTrue(Str::startsWith($url, 'https://front.example.test/verify-email?'));

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return $query;
    }
}
