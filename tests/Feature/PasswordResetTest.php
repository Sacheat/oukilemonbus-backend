<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\Auth\QueuedResetPassword;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Queue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.frontend_url' => 'https://front.example.test', 'app.frontend_password_reset_path' => '/reset-password']);
    }

    public function test_forgot_password_sends_a_link_to_the_frontend(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'oubli@example.test']);

        $this->postJson('/api/auth/forgot-password', ['email' => 'oubli@example.test'])->assertOk();

        Notification::assertSentTo($user, QueuedResetPassword::class, function (QueuedResetPassword $notification) use ($user): bool {
            $url = $notification->toMail($user)->actionUrl;

            return str_starts_with($url, 'https://front.example.test/reset-password?token=')
                && str_contains($url, 'email=oubli%40example.test');
        });
    }

    public function test_forgot_password_email_is_queued_so_the_request_does_not_depend_on_smtp(): void
    {
        Queue::fake();
        User::factory()->create(['email' => 'file@example.test']);

        $this->postJson('/api/auth/forgot-password', ['email' => 'file@example.test'])->assertOk();

        Queue::assertPushed(SendQueuedNotifications::class, fn (SendQueuedNotifications $job): bool => $job->notification instanceof QueuedResetPassword);
    }

    public function test_unknown_email_gets_the_same_response(): void
    {
        Notification::fake();
        User::factory()->create(['email' => 'connu@example.test']);

        $known = $this->postJson('/api/auth/forgot-password', ['email' => 'connu@example.test'])->json();
        $unknown = $this->postJson('/api/auth/forgot-password', ['email' => 'inconnu@example.test'])->assertOk()->json();

        $this->assertSame($known, $unknown);
    }

    public function test_valid_token_resets_password_and_revokes_api_tokens(): void
    {
        $user = User::factory()->create(['email' => 'reset@example.test']);
        $user->createToken('ancien');
        $token = Password::broker()->createToken($user);

        $this->postJson('/api/auth/reset-password', [
            'token' => $token,
            'email' => 'reset@example.test',
            'password' => 'NouveauMotDePasse1!',
            'password_confirmation' => 'NouveauMotDePasse1!',
        ])->assertOk();

        $user->refresh();
        $this->assertTrue(Hash::check('NouveauMotDePasse1!', $user->password));
        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_invalid_token_is_rejected(): void
    {
        $user = User::factory()->create(['email' => 'mauvais@example.test']);

        $this->postJson('/api/auth/reset-password', [
            'token' => 'faux-jeton',
            'email' => 'mauvais@example.test',
            'password' => 'NouveauMotDePasse1!',
            'password_confirmation' => 'NouveauMotDePasse1!',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');

        $this->assertFalse(Hash::check('NouveauMotDePasse1!', $user->fresh()->password));
    }

    public function test_reset_works_while_another_account_is_logged_in_and_keeps_that_session(): void
    {
        $admin = User::factory()->create(['email' => 'admin.session@example.test']);
        $user = User::factory()->create(['email' => 'lien@example.test']);
        $this->storeSession('session-admin', $admin);
        $this->storeSession('session-lien', $user);

        $this->actingAs($admin, 'web')
            ->postJson('/api/auth/reset-password', $this->resetPayload($user))
            ->assertOk()
            ->assertJsonPath('message', 'Votre mot de passe a été réinitialisé. Vous pouvez vous connecter.');

        $this->assertTrue(Hash::check('NouveauMotDePasse1!', $user->fresh()->password));
        $this->assertAuthenticatedAs($admin, 'web');
        $this->assertDatabaseHas('sessions', ['id' => 'session-admin']);
        $this->assertDatabaseMissing('sessions', ['id' => 'session-lien']);
    }

    public function test_reset_of_the_logged_in_account_closes_its_sessions(): void
    {
        $user = User::factory()->create(['email' => 'moi@example.test']);
        $this->storeSession('session-moi', $user);

        $this->actingAs($user, 'web')
            ->postJson('/api/auth/reset-password', $this->resetPayload($user))
            ->assertOk();

        $this->assertTrue(Hash::check('NouveauMotDePasse1!', $user->fresh()->password));
        $this->assertGuest('web');
        $this->assertDatabaseMissing('sessions', ['id' => 'session-moi']);
    }

    public function test_guest_only_routes_answer_409_json_when_a_session_is_open(): void
    {
        $user = User::factory()->create(['email' => 'connecte@example.test']);
        $this->actingAs($user, 'web');

        $requests = [
            '/api/auth/login' => ['email' => 'connecte@example.test', 'password' => 'password'],
            '/api/auth/register' => ['first_name' => 'A', 'last_name' => 'B', 'email' => 'autre@example.test', 'password' => 'Password123!', 'password_confirmation' => 'Password123!'],
            '/api/auth/forgot-password' => ['email' => 'connecte@example.test'],
        ];

        foreach ($requests as $uri => $body) {
            // Sans header Accept, comme un fetch qui l'oublierait : jamais de redirection.
            $this->post($uri, $body)
                ->assertStatus(409)
                ->assertExactJson(['message' => 'Vous êtes déjà connecté. Déconnectez-vous pour continuer.']);
        }

        $this->assertDatabaseMissing('users', ['email' => 'autre@example.test']);
    }

    /** @return array<string, string> */
    private function resetPayload(User $user): array
    {
        return [
            'token' => Password::broker()->createToken($user),
            'email' => $user->email,
            'password' => 'NouveauMotDePasse1!',
            'password_confirmation' => 'NouveauMotDePasse1!',
        ];
    }

    // Ligne de la table sessions (driver database en dev) : le service supprime celles du compte réinitialisé.
    private function storeSession(string $id, User $user): void
    {
        DB::table('sessions')->insert([
            'id' => $id,
            'user_id' => $user->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'phpunit',
            'payload' => '',
            'last_activity' => now()->getTimestamp(),
        ]);
    }
}
