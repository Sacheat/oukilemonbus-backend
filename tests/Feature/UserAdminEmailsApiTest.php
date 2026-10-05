<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\Auth\QueuedResetPassword;
use App\Notifications\Auth\QueuedVerifyEmail;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TestUsersSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Emails envoyés par l'admin depuis la page « Utilisateurs » : vérification à la création d'un compte
 * et lien de réinitialisation du mot de passe (POST /users/{user}/password-reset-link).
 */
class UserAdminEmailsApiTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->seed([RolesAndPermissionsSeeder::class, TestUsersSeeder::class]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        config(['app.frontend_url' => 'https://front.example.test', 'app.frontend_password_reset_path' => '/reset-password']);
        Notification::fake();

        $this->admin = $this->seeded('admin.test@bus-communal.local');
        Sanctum::actingAs($this->admin);
    }

    public function test_created_account_receives_the_verification_email(): void
    {
        $response = $this->postJson('/api/users', $this->newAccount())->assertCreated();

        $user = User::findOrFail($response->json('data.id'));
        $this->assertNull($user->email_verified_at);
        Notification::assertSentTo($user, QueuedVerifyEmail::class);
    }

    public function test_no_verification_email_when_the_account_is_already_verified(): void
    {
        User::creating(fn (User $user) => $user->email_verified_at = now());

        $response = $this->postJson('/api/users', $this->newAccount())->assertCreated();

        Notification::assertNotSentTo(User::findOrFail($response->json('data.id')), QueuedVerifyEmail::class);
    }

    public function test_admin_sends_the_forgot_password_link_without_exposing_the_token(): void
    {
        $target = $this->seeded('user.test@bus-communal.local');

        $response = $this->postJson("/api/users/{$target->id}/password-reset-link")
            ->assertAccepted()
            ->assertExactJson(['message' => 'Un lien de réinitialisation a été envoyé à user.test@bus-communal.local.']);

        Notification::assertSentTo($target, QueuedResetPassword::class, function (QueuedResetPassword $notification) use ($target, $response): bool {
            $url = $notification->toMail($target)->actionUrl;

            return str_starts_with($url, 'https://front.example.test/reset-password?token=')
                && str_contains($url, 'email=user.test%40bus-communal.local')
                && ! str_contains($response->getContent(), $notification->token);
        });
        // L'admin ne fixe aucun mot de passe : celui du compte n'a pas changé.
        $this->assertTrue(Hash::check('password', $target->fresh()->password));
    }

    public function test_agent_cannot_send_a_reset_link(): void
    {
        Sanctum::actingAs($this->seeded('agent.test@bus-communal.local'));

        $this->postJson("/api/users/{$this->seeded('user.test@bus-communal.local')->id}/password-reset-link")->assertForbidden();

        Notification::assertNothingSent();
    }

    public function test_no_link_for_a_deactivated_account(): void
    {
        $target = $this->seeded('user.test@bus-communal.local');
        $target->update(['active' => false]);

        $this->postJson("/api/users/{$target->id}/password-reset-link")
            ->assertUnprocessable()
            ->assertJsonPath('message', "Ce compte est désactivé : réactivez-le avant d'envoyer un lien.");

        Notification::assertNothingSent();
    }

    public function test_a_second_link_too_soon_is_refused(): void
    {
        $target = $this->seeded('user.test@bus-communal.local');

        $this->postJson("/api/users/{$target->id}/password-reset-link")->assertAccepted();
        $this->postJson("/api/users/{$target->id}/password-reset-link")
            ->assertUnprocessable()
            ->assertJsonPath('message', "Un lien de réinitialisation vient déjà d'être envoyé à ce compte. Réessayez dans une minute.");

        Notification::assertSentToTimes($target, QueuedResetPassword::class, 1);
    }

    /** @return array<string, string> */
    private function newAccount(): array
    {
        return [
            'first_name' => 'Nouvelle',
            'last_name' => 'Personne',
            'email' => 'nouvelle.personne@example.test',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ];
    }

    private function seeded(string $email): User
    {
        return User::query()->where('email', $email)->firstOrFail();
    }
}
