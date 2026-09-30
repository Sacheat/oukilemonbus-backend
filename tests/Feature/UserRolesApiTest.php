<?php

namespace Tests\Feature;

use App\Models\Driver;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TestFleetSeeder;
use Database\Seeders\TestUsersSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class UserRolesApiTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->seed([RolesAndPermissionsSeeder::class, TestUsersSeeder::class]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_admin_can_replace_global_roles(): void
    {
        $target = $this->seeded('user.test@bus-communal.local');
        Sanctum::actingAs($this->seeded('admin.test@bus-communal.local'));

        $this->patchJson("/api/users/{$target->id}/roles", ['roles' => ['agent_communal']])
            ->assertOk()
            ->assertJsonPath('data.roles', ['agent_communal']);

        $this->assertTrue($target->fresh()->hasRole('agent_communal'));
        $this->assertFalse($target->fresh()->hasRole('user'));
    }

    public function test_agent_cannot_manage_roles(): void
    {
        $target = $this->seeded('user.test@bus-communal.local');
        Sanctum::actingAs($this->seeded('agent.test@bus-communal.local'));

        $this->patchJson("/api/users/{$target->id}/roles", ['roles' => ['admin']])->assertForbidden();
    }

    public function test_unknown_or_removed_role_is_rejected(): void
    {
        $target = $this->seeded('user.test@bus-communal.local');
        Sanctum::actingAs($this->seeded('admin.test@bus-communal.local'));

        $this->patchJson("/api/users/{$target->id}/roles", ['roles' => ['responsable_organisation']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('roles.0');
    }

    public function test_admin_cannot_remove_own_admin_role(): void
    {
        $admin = $this->seeded('admin.test@bus-communal.local');
        Sanctum::actingAs($admin);

        $this->patchJson("/api/users/{$admin->id}/roles", ['roles' => ['user']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('roles');

        $this->assertTrue($admin->fresh()->hasRole('admin'));
    }

    public function test_created_user_gets_the_user_role(): void
    {
        Sanctum::actingAs($this->seeded('admin.test@bus-communal.local'));

        $response = $this->postJson('/api/users', [
            'first_name' => 'Nouvel',
            'last_name' => 'Agent',
            'email' => 'nouvel.agent@example.test',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ])->assertCreated();

        $this->assertTrue(User::findOrFail($response->json('data.id'))->hasRole('user'));
    }

    public function test_creating_a_driver_profile_grants_the_chauffeur_role(): void
    {
        $user = User::factory()->create();
        $user->assignRole('user');
        Sanctum::actingAs($this->seeded('agent.test@bus-communal.local'));

        $this->postJson('/api/drivers', [
            'user_id' => $user->id,
            'licence_number' => 'DRV-ROLE-001',
            'licence_expiry_date' => now()->addYear()->toDateString(),
        ])->assertCreated();

        $this->assertTrue($user->fresh()->hasRole('chauffeur'));
    }

    public function test_seeded_driver_profiles_have_the_chauffeur_role(): void
    {
        $this->seed(TestFleetSeeder::class);

        $this->assertTrue($this->seeded('driver.second@bus-communal.local')->hasRole('chauffeur'));
        Driver::query()->with('user')->get()->each(
            fn (Driver $driver) => $this->assertTrue($driver->user->hasRole('chauffeur'), $driver->user->email)
        );
    }

    private function seeded(string $email): User
    {
        return User::query()->where('email', $email)->firstOrFail();
    }
}
