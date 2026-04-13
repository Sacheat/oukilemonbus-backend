<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class AuthUserApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach ([
            'users.view',
            'users.create',
            'users.update',
            'users.update-status',
            'users.deactivate',
        ] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        Role::findOrCreate('admin', 'web')->givePermissionTo(Permission::all());
        Role::findOrCreate('user', 'web')->givePermissionTo([
            'users.view',
        ]);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_user_can_register_and_is_authenticated(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'first_name' => 'Alice',
            'last_name' => 'Martin',
            'email' => ' Alice@example.test ',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'phone' => '082000000',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.email', 'alice@example.test')
            ->assertJsonMissingPath('data.password')
            ->assertJsonMissingPath('data.remember_token');

        $this->assertAuthenticated();
        $this->assertTrue(User::where('email', 'alice@example.test')->firstOrFail()->hasRole('user'));
        $this->assertDatabaseHas('users', [
            'email' => 'alice@example.test',
            'first_name' => 'Alice',
            'active' => true,
        ]);
    }

    public function test_user_can_login_with_valid_credentials(): void
    {
        $user = User::factory()->create([
            'email' => 'alice@example.test',
            'password' => 'Password123!',
            'active' => true,
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'ALICE@example.test',
            'password' => 'Password123!',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonMissingPath('data.password')
            ->assertJsonMissingPath('data.remember_token');

        $this->assertAuthenticatedAs($user);
    }

    public function test_inactive_user_cannot_login(): void
    {
        User::factory()->create([
            'email' => 'inactive@example.test',
            'password' => 'Password123!',
            'active' => false,
        ]);

        $this->postJson('/api/auth/login', [
            'email' => 'inactive@example.test',
            'password' => 'Password123!',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        $this->assertGuest();
    }

    public function test_login_is_rate_limited_after_repeated_failures(): void
    {
        RateLimiter::clear('blocked@example.test|127.0.0.1');

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->postJson('/api/auth/login', [
                'email' => 'blocked@example.test',
                'password' => 'wrong-password',
            ])->assertUnprocessable();
        }

        $this->postJson('/api/auth/login', [
            'email' => 'blocked@example.test',
            'password' => 'wrong-password',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    }

    public function test_authenticated_user_can_fetch_their_profile_and_logout(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.id', $user->id);

        $this->postJson('/api/auth/logout')->assertNoContent();

        $this->assertGuest();
    }

    public function test_guest_cannot_access_user_endpoints(): void
    {
        $user = User::factory()->create();

        $this->getJson('/api/auth/me')->assertUnauthorized();
        $this->patchJson("/api/users/{$user->id}", [
            'first_name' => 'Blocked',
        ])->assertUnauthorized();
    }

    public function test_authenticated_user_can_update_their_profile(): void
    {
        $user = User::factory()->create([
            'email' => 'alice@example.test',
        ]);
        $user->assignRole('user');

        $this->actingAs($user)
            ->patchJson("/api/users/{$user->id}", [
                'first_name' => 'Alicia',
                'email' => ' ALICIA@example.test ',
                'phone' => null,
            ])
            ->assertOk()
            ->assertJsonPath('data.first_name', 'Alicia')
            ->assertJsonPath('data.email', 'alicia@example.test');

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'email' => 'alicia@example.test',
        ]);
    }

    public function test_authenticated_user_cannot_update_another_profile_with_self_service_request(): void
    {
        $actor = User::factory()->create();
        $other = User::factory()->create();
        $actor->assignRole('user');

        $this->actingAs($actor)
            ->patchJson("/api/users/{$other->id}", [
                'first_name' => 'Intrusion',
            ])
            ->assertForbidden();
    }

    public function test_authenticated_user_can_update_their_password(): void
    {
        $user = User::factory()->create([
            'password' => 'OldPassword123!',
        ]);
        $user->assignRole('user');

        $this->actingAs($user)
            ->patchJson("/api/users/{$user->id}/password", [
                'current_password' => 'OldPassword123!',
                'password' => 'NewPassword123!',
                'password_confirmation' => 'NewPassword123!',
            ])
            ->assertNoContent();

        $this->assertTrue(Hash::check('NewPassword123!', $user->fresh()->password));
    }

    public function test_admin_can_deactivate_a_user(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $user = User::factory()->create([
            'active' => true,
        ]);

        $this->actingAs($admin)
            ->patchJson("/api/users/{$user->id}/deactivate")
            ->assertNoContent();

        $this->assertFalse($user->fresh()->active);
    }

    public function test_user_without_permission_cannot_deactivate_a_user(): void
    {
        $actor = User::factory()->create();
        $target = User::factory()->create();
        $actor->assignRole('user');

        $this->actingAs($actor)
            ->patchJson("/api/users/{$target->id}/deactivate")
            ->assertForbidden();

        $this->assertTrue($target->fresh()->active);
    }

    public function test_admin_can_list_show_create_and_update_status_for_users(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $user = User::factory()->create([
            'active' => true,
        ]);

        $this->actingAs($admin)
            ->getJson('/api/users')
            ->assertOk()
            ->assertJsonFragment([
                'email' => $user->email,
            ]);

        $this->actingAs($admin)
            ->getJson("/api/users/{$user->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $user->id);

        $this->actingAs($admin)
            ->postJson('/api/users', [
                'first_name' => 'Created',
                'last_name' => 'User',
                'email' => 'created@example.test',
                'password' => 'Password123!',
                'password_confirmation' => 'Password123!',
            ])
            ->assertCreated()
            ->assertJsonPath('data.email', 'created@example.test');

        $this->actingAs($admin)
            ->patchJson("/api/users/{$user->id}/status", [
                'active' => false,
            ])
            ->assertOk()
            ->assertJsonPath('data.active', false);
    }
}
