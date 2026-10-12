<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class TourApiTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->seed(RolesAndPermissionsSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_me_returns_empty_tours_for_a_new_account(): void
    {
        $user = User::factory()->create();
        $user->assignRole('user');
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/auth/me')->assertOk();

        // Objet JSON vide, pas un tableau : le front lit `tours_seen.usager` sans cas particulier.
        $this->assertStringContainsString('"tours_seen":{}', $response->getContent());
    }

    public function test_marking_a_tour_seen_is_returned_by_me_and_keeps_the_first_date(): void
    {
        $user = User::factory()->create();
        $user->assignRole('user');
        Sanctum::actingAs($user);

        $first = $this->patchJson('/api/auth/me/tours/usager')
            ->assertOk()
            ->json('data.tours_seen.usager');
        $this->assertNotNull($first);

        $this->travel(1)->hours();
        $this->patchJson('/api/auth/me/tours/usager')
            ->assertOk()
            ->assertJsonPath('data.tours_seen.usager', $first);

        $this->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.tours_seen.usager', $first)
            ->assertJsonMissingPath('data.tours_seen.agent');
    }

    public function test_reset_forgets_only_that_tour(): void
    {
        $user = User::factory()->create();
        $user->assignRole('agent_communal');
        Sanctum::actingAs($user);

        $this->patchJson('/api/auth/me/tours/agent')->assertOk();
        $this->patchJson('/api/auth/me/tours/chauffeur')->assertOk();

        $this->deleteJson('/api/auth/me/tours/agent')
            ->assertOk()
            ->assertJsonMissingPath('data.tours_seen.agent')
            ->assertJsonPath('data.tours_seen.chauffeur', fn ($date) => $date !== null);

        $this->deleteJson('/api/auth/me/tours/chauffeur')->assertOk();
        $this->assertNull($user->fresh()->tours_seen);
    }

    public function test_unknown_tour_is_rejected(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->patchJson('/api/auth/me/tours/inconnu')
            ->assertUnprocessable()
            ->assertJsonPath('errors.tour.0', 'Ce tutoriel n’existe pas.');

        $this->assertNull($user->fresh()->tours_seen);
    }

    public function test_tours_only_change_the_current_account(): void
    {
        $me = User::factory()->create();
        $other = User::factory()->create();
        Sanctum::actingAs($me);

        $this->patchJson('/api/auth/me/tours/usager')->assertOk();

        $this->assertNull($other->fresh()->tours_seen);
        $this->assertArrayHasKey('usager', $me->fresh()->tours_seen);
    }

    public function test_tours_are_not_exposed_in_the_admin_user_list(): void
    {
        $admin = User::factory()->create(['tours_seen' => ['agent' => now()->toISOString()]]);
        $admin->assignRole('admin');
        Sanctum::actingAs($admin);

        $this->getJson("/api/users/{$admin->id}")
            ->assertOk()
            ->assertJsonMissingPath('data.tours_seen');
    }

    public function test_guests_cannot_mark_a_tour(): void
    {
        $this->patchJson('/api/auth/me/tours/usager')->assertUnauthorized();
    }
}
