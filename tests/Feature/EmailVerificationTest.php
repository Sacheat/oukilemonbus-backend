<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
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
            ->assertForbidden();

        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    private function verificationQuery(User $user): array
    {
        Notification::fake();
        $user->sendEmailVerificationNotification();

        $url = null;
        Notification::assertSentTo($user, VerifyEmail::class, function (VerifyEmail $notification) use ($user, &$url): bool {
            $url = $notification->toMail($user)->actionUrl;

            return true;
        });

        $this->assertTrue(Str::startsWith($url, 'https://front.example.test/verify-email?'));

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return $query;
    }
}
