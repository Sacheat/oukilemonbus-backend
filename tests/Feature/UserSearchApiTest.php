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
