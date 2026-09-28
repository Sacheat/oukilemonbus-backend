<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user): bool {
            $url = $notification->toMail($user)->actionUrl;

            return str_starts_with($url, 'https://front.example.test/reset-password?token=')
                && str_contains($url, 'email=oubli%40example.test');
        });
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
}
