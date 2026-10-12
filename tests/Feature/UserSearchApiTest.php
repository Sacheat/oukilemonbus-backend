<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TestFleetSeeder;
use Database\Seeders\TestUsersSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * GET /api/users?search= : recherche de compte (ex. à l'ajout d'un chauffeur).
 */
class UserSearchApiTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->seed([RolesAndPermissionsSeeder::class, TestUsersSeeder::class, TestFleetSeeder::class]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Sanctum::actingAs(User::query()->where('email', 'agent.test@bus-communal.local')->firstOrFail());
    }

    public function test_search_matches_every_word_in_first_name_last_name_or_email(): void
    {
        $this->assertSame(['driver.second@bus-communal.local'], $this->emailsFor('paul sec'));
        $this->assertSame(['driver.second@bus-communal.local'], $this->emailsFor('SECOND'));
        $this->assertSame(['reviewer.test@bus-communal.local'], $this->emailsFor('reviewer.test@'));
        $this->assertSame([], $this->emailsFor('paul reviewer'));
    }

    public function test_like_wildcards_are_matched_literally(): void
    {
        $this->assertSame([], $this->emailsFor('%'));
        $this->assertSame([], $this->emailsFor('_'));
    }

    public function test_without_search_all_users_are_listed_and_pagination_keeps_the_search(): void
    {
        $this->getJson('/api/users')
            ->assertOk()
            ->assertJsonPath('meta.total', User::count());

        $this->getJson('/api/users?search=bus-communal&page=1')
            ->assertOk()
            ->assertJsonPath('links.first', fn (string $url) => str_contains($url, 'search=bus-communal'));
    }

    public function test_role_counts_follow_search_and_status_but_not_the_role_filter(): void
    {
        $expected = fn (?string $role, ?bool $active = null) => User::query()
            ->when($role, fn ($query) => $query->role($role))
            ->when($active !== null, fn ($query) => $query->where('active', $active))
            ->count();

        $counts = $this->getJson('/api/users?role=chauffeur')->assertOk()->json('meta.role_counts');
        $this->assertSame($expected(null), $counts['all']);
        foreach (User::GLOBAL_ROLES as $role) {
            $this->assertSame($expected($role), $counts[$role], $role);
        }
        // La liste reste filtrée par rôle, pas les compteurs
        $this->getJson('/api/users?role=chauffeur')->assertJsonPath('meta.total', $expected('chauffeur'));

        User::query()->where('email', 'driver.second@bus-communal.local')->update(['active' => false]);
        $this->getJson('/api/users?active=0')
            ->assertOk()
            ->assertJsonPath('meta.role_counts.all', $expected(null, false))
            ->assertJsonPath('meta.role_counts.chauffeur', $expected('chauffeur', false));

        $this->getJson('/api/users?search=paul+sec')
            ->assertOk()
            ->assertJsonPath('meta.role_counts.all', 1)
            ->assertJsonPath('meta.role_counts.admin', 0);
    }

    public function test_search_is_validated(): void
    {
        $this->getJson('/api/users?search='.str_repeat('a', 101))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('search');
    }

    /** @return list<string> */
    private function emailsFor(string $search): array
    {
        return collect($this->getJson('/api/users?search='.urlencode($search))->assertOk()->json('data'))
            ->pluck('email')
            ->sort()
            ->values()
            ->all();
    }
}
