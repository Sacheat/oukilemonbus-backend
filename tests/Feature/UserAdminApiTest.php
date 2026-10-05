<?php

namespace Tests\Feature;

use App\Models\Driver;
use App\Models\User;
use App\Services\Users\UserService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TestFleetSeeder;
use Database\Seeders\TestUsersSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Page d'administration des utilisateurs : filtres de GET /api/users, détail d'un compte
 * et règle « la plateforme garde toujours un administrateur actif ».
 */
class UserAdminApiTest extends TestCase
{
    use DatabaseTransactions;

    private const string LAST_ADMIN_ROLES = 'Impossible de retirer le rôle administrateur du dernier administrateur actif.';

    private const string LAST_ADMIN_DEACTIVATION = 'Impossible de désactiver le dernier administrateur actif.';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->seed([RolesAndPermissionsSeeder::class, TestUsersSeeder::class, TestFleetSeeder::class]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->admin = $this->seeded('admin.test@bus-communal.local');
        Sanctum::actingAs($this->admin);
    }

    public function test_users_can_be_filtered_by_role(): void
    {
        $this->assertSame(
            ['agent.test@bus-communal.local', 'reviewer.test@bus-communal.local'],
            $this->emailsFor('role=agent_communal')
        );
        $this->assertSame(['admin.test@bus-communal.local'], $this->emailsFor('role=admin'));
    }

    public function test_users_can_be_filtered_by_status(): void
    {
        $this->seeded('reviewer.test@bus-communal.local')->update(['active' => false]);

        $this->assertSame(['reviewer.test@bus-communal.local'], $this->emailsFor('active=0'));
        $this->assertSame(['reviewer.test@bus-communal.local'], $this->emailsFor('active=false'));
        $this->assertNotContains('reviewer.test@bus-communal.local', $this->emailsFor('active=1'));
        $this->getJson('/api/users?active=true')
            ->assertOk()
            ->assertJsonPath('meta.total', User::where('active', true)->count());
    }

    public function test_filters_combine_with_search(): void
    {
        $this->seeded('reviewer.test@bus-communal.local')->update(['active' => false]);

        $this->assertSame(['agent.test@bus-communal.local'], $this->emailsFor('role=agent_communal&active=1'));
        $this->assertSame(['reviewer.test@bus-communal.local'], $this->emailsFor('role=agent_communal&search=nora'));
        $this->assertSame([], $this->emailsFor('role=agent_communal&active=1&search=nora'));
    }

    public function test_invalid_filters_are_rejected_in_french(): void
    {
        $this->getJson('/api/users?role=responsable_organisation')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['role' => 'Le champ rôle est invalide.']);

        $this->getJson('/api/users?active=peut-etre')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['active' => 'Le champ statut du compte doit être vrai ou faux.']);
    }

    public function test_pagination_links_keep_the_filters(): void
    {
        $response = $this->getJson('/api/users?role=agent_communal&active=1&search=test&page=1')->assertOk();

        foreach (['role=agent_communal', 'active=1', 'search=test'] as $parameter) {
            $this->assertStringContainsString($parameter, $response->json('links.first'));
            $this->assertStringContainsString($parameter, $response->json('links.last'));
            $this->assertStringContainsString($parameter, $response->json('meta.links.1.url'));
        }
    }

    public function test_show_includes_roles_organizations_and_driver(): void
    {
        $driver = Driver::query()->with('user')->firstOrFail();

        $this->getJson("/api/users/{$driver->user->id}")
            ->assertOk()
            ->assertJsonPath('data.roles', fn (array $roles) => in_array('chauffeur', $roles, true))
            ->assertJsonPath('data.driver.id', $driver->id)
            ->assertJsonPath('data.organizations', []);
    }

    public function test_last_active_admin_cannot_be_deactivated_through_status(): void
    {
        $this->assertSoleActiveAdmin();

        $this->patchJson("/api/users/{$this->admin->id}/status", ['active' => false])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['active' => self::LAST_ADMIN_DEACTIVATION]);

        $this->assertTrue($this->admin->fresh()->active);
    }

    public function test_last_active_admin_cannot_be_deactivated(): void
    {
        $this->assertSoleActiveAdmin();
        $this->admin->createToken('spa');

        $this->patchJson("/api/users/{$this->admin->id}/deactivate")
            ->assertUnprocessable()
            ->assertJsonPath('message', self::LAST_ADMIN_DEACTIVATION);

        $this->assertTrue($this->admin->fresh()->active);
        $this->assertSame(1, $this->admin->tokens()->count());
    }

    public function test_last_active_admin_keeps_the_admin_role(): void
    {
        $this->assertSoleActiveAdmin();

        // Inaccessible par HTTP (seul un admin actif gère les rôles, et la règle « pas son propre rôle »
        // passe avant) : la règle est vérifiée au niveau du service.
        try {
            app(UserService::class)->updateRoles($this->admin, ['agent_communal']);
            $this->fail('Le retrait du rôle admin aurait dû être refusé.');
        } catch (ValidationException $exception) {
            $this->assertSame([self::LAST_ADMIN_ROLES], $exception->errors()['roles']);
        }

        $this->assertTrue($this->admin->fresh()->hasRole('admin'));
    }

    public function test_an_inactive_admin_does_not_count(): void
    {
        $inactiveAdmin = User::factory()->create(['active' => false]);
        $inactiveAdmin->assignRole('admin');

        $this->patchJson("/api/users/{$this->admin->id}/status", ['active' => false])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['active' => self::LAST_ADMIN_DEACTIVATION]);
    }

    public function test_admin_changes_are_accepted_when_another_active_admin_exists(): void
    {
        $other = User::factory()->create(['active' => true]);
        $other->assignRole('admin');

        $this->patchJson("/api/users/{$other->id}/roles", ['roles' => ['agent_communal']])
            ->assertOk()
            ->assertJsonPath('data.roles', ['agent_communal']);

        $other->syncRoles(['admin']);
        $this->patchJson("/api/users/{$other->id}/status", ['active' => false])
            ->assertOk()
            ->assertJsonPath('data.active', false);

        $other->refresh()->update(['active' => true]);
        $this->patchJson("/api/users/{$this->admin->id}/deactivate")->assertNoContent();
        $this->assertFalse($this->admin->fresh()->active);
    }

    public function test_an_inactive_admin_can_lose_the_admin_role(): void
    {
        $inactiveAdmin = User::factory()->create(['active' => false]);
        $inactiveAdmin->assignRole('admin');

        $this->patchJson("/api/users/{$inactiveAdmin->id}/roles", ['roles' => ['user']])->assertOk();
    }

    private function assertSoleActiveAdmin(): void
    {
        $this->assertSame([$this->admin->id], User::role('admin')->where('active', true)->pluck('id')->all());
    }

    /** @return list<string> */
    private function emailsFor(string $query): array
    {
        return collect($this->getJson('/api/users?'.$query)->assertOk()->json('data'))
            ->pluck('email')
            ->sort()
            ->values()
            ->all();
    }

    private function seeded(string $email): User
    {
        return User::query()->where('email', $email)->firstOrFail();
    }
}
