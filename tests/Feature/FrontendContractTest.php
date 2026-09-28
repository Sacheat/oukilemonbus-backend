<?php

namespace Tests\Feature;

use App\Enums\OrganizationType;
use App\Models\Organization;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Forme des réponses sur lesquelles la SPA s'appuie.
 */
class FrontendContractTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_me_exposes_effective_permissions_and_memberships(): void
    {
        $organization = Organization::create([
            'name' => 'École du Contrat',
            'type' => OrganizationType::ECOLE_COMMUNALE,
            'official_email' => 'contrat@ecole.test',
            'annual_quota' => 5,
            'reservations_used_this_year' => 0,
            'quota_reset_year' => now(),
        ]);
        $user = User::factory()->create();
        $user->assignRole('user');
        $user->organizations()->attach($organization->id, ['relationship_type' => 'directeur', 'is_default' => true]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.roles', ['user'])
            ->assertJsonPath('data.organizations.0.type_label', 'École communale')
            ->assertJsonPath('data.organizations.0.membership', [
                'relationship_type' => 'directeur',
                'is_default' => true,
                'is_manager' => true,
            ]);

        $permissions = $response->json('data.permissions');
        $this->assertContains('reservations.create', $permissions);
        $this->assertNotContains('reservations.approve', $permissions);
    }

    public function test_api_errors_are_json_even_without_accept_header(): void
    {
        $this->get('/api/auth/me')
            ->assertUnauthorized()
            ->assertHeader('Content-Type', 'application/json');

        $this->post('/api/auth/login', [])
            ->assertUnprocessable()
            ->assertJsonStructure(['message', 'errors' => ['email', 'password']]);
    }

    public function test_reservation_index_requires_the_view_permission(): void
    {
        $user = User::factory()->create(); // aucun rôle, donc aucune permission
        Sanctum::actingAs($user);

        $this->getJson('/api/reservations')->assertForbidden();
    }
}
