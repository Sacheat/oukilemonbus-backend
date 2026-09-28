<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Les messages renvoyés au frontend sont en français (lang/fr + App\Exceptions\ApiErrorMessage).
 */
class ApiFrenchMessagesTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('fr');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_validation_messages_use_french_field_names(): void
    {
        $user = User::factory()->create();
        $user->assignRole('user');
        Sanctum::actingAs($user);

        $this->postJson('/api/reservations', [])
            ->assertUnprocessable()
            ->assertJsonPath('errors.trip_date.0', 'Le champ date du trajet est obligatoire.')
            ->assertJsonPath('errors.nb_adults.0', 'Le champ nombre d\'adultes est obligatoire.');
    }

    public function test_failed_login_message_is_french(): void
    {
        $this->postJson('/api/auth/login', ['email' => 'inconnu@example.test', 'password' => 'x'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.email.0', 'Ces identifiants ne correspondent pas à nos enregistrements.');
    }

    public function test_framework_errors_are_french(): void
    {
        $this->getJson('/api/auth/me')
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Vous devez être connecté.']);

        $unverified = User::factory()->unverified()->create();
        $unverified->assignRole('user');
        Sanctum::actingAs($unverified);
        $this->getJson('/api/reservations')
            ->assertForbidden()
            ->assertJsonPath('message', 'Votre adresse e-mail n’est pas encore vérifiée.');

        $user = User::factory()->create();
        $user->assignRole('user');
        Sanctum::actingAs($user);
        $this->getJson('/api/vehicles')
            ->assertForbidden()
            ->assertJsonPath('message', 'Vous n’avez pas les droits nécessaires pour cette action.');

        $this->getJson('/api/reservations/999999')
            ->assertNotFound()
            ->assertJsonPath('message', 'Ressource introuvable.');
    }
}
