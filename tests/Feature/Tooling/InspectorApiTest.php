<?php

namespace Tests\Feature\Tooling;

use App\Models\User;
use App\Tooling\Inspector\Models\InspectorEntry;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TestUsersSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class InspectorApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->seed([RolesAndPermissionsSeeder::class, TestUsersSeeder::class]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        config(['tooling.inspector.enabled' => true]);
    }

    public function test_guest_cannot_access_inspector_api(): void
    {
        $this->getJson('/api/admin/tooling/inspector/entries')->assertUnauthorized();
    }

    public function test_non_admin_roles_are_forbidden(): void
    {
        foreach (['agent', 'responsable', 'chauffeur', 'user'] as $prefix) {
            Sanctum::actingAs($this->seededUser("{$prefix}.test@bus-communal.local"));

            $this->getJson('/api/admin/tooling/inspector/entries')->assertForbidden();
        }
    }

    public function test_mutation_is_recorded_with_actor_and_flow_key(): void
    {
        $admin = $this->seededUser('admin.test@bus-communal.local');
        Sanctum::actingAs($admin);

        $this->patchJson('/api/notifications/read-all')->assertSuccessful();

        $entry = InspectorEntry::query()->sole();

        $this->assertSame($admin->id, $entry->user_id);
        $this->assertSame($admin->email, $entry->user_email);
        $this->assertSame('PATCH', $entry->method);
        $this->assertSame('api.notifications.mark-all-read', $entry->route_name);
        $this->assertSame('notifications.read_all', $entry->flow_key);
        $this->assertSame(InspectorEntry::OUTCOME_SUCCESS, $entry->outcome);
    }

    public function test_failed_request_is_recorded_as_error_with_sensitive_fields_redacted(): void
    {
        $this->postJson('/api/auth/login', [
            'email' => 'admin.test@bus-communal.local',
            'password' => 'wrong-password',
            'nested' => ['api_token' => 'abc', 'note' => 'visible'],
        ])->assertUnprocessable();

        $entry = InspectorEntry::query()->sole();

        $this->assertSame(InspectorEntry::OUTCOME_ERROR, $entry->outcome);
        $this->assertSame(422, $entry->http_status);
        $this->assertSame('auth.login', $entry->flow_key);
        $this->assertSame('[masqué]', $entry->request_payload['password']);
        $this->assertSame('[masqué]', $entry->request_payload['nested']['api_token']);
        $this->assertSame('visible', $entry->request_payload['nested']['note']);
        $this->assertArrayHasKey('errors', $entry->response_summary);
        $this->assertStringNotContainsString('wrong-password', json_encode($entry->getAttributes()));
    }

    public function test_read_requests_and_tooling_routes_are_not_recorded(): void
    {
        Sanctum::actingAs($this->seededUser('admin.test@bus-communal.local'));

        $this->getJson('/api/notifications')->assertOk();
        $this->deleteJson('/api/admin/tooling/inspector/entries')->assertOk();

        $this->assertSame(0, InspectorEntry::query()->count());
    }

    public function test_nothing_is_recorded_when_inspector_is_disabled(): void
    {
        config(['tooling.inspector.enabled' => false]);
        Sanctum::actingAs($this->seededUser('admin.test@bus-communal.local'));

        $this->patchJson('/api/notifications/read-all')->assertSuccessful();

        $this->assertSame(0, InspectorEntry::query()->count());
    }

    public function test_admin_can_list_filter_and_show_entries(): void
    {
        $admin = $this->seededUser('admin.test@bus-communal.local');
        $this->makeEntry(['user_id' => $admin->id, 'outcome' => 'success', 'http_status' => 200]);
        $error = $this->makeEntry(['outcome' => 'error', 'http_status' => 422, 'flow_key' => 'auth.login']);

        Sanctum::actingAs($admin);

        $this->getJson('/api/admin/tooling/inspector/entries')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonStructure(['data', 'links', 'meta']);

        $this->getJson('/api/admin/tooling/inspector/entries?outcome=error')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $error->id)
            ->assertJsonPath('data.0.flow.category', 'Authentification');

        $this->getJson("/api/admin/tooling/inspector/entries/{$error->id}")
            ->assertOk()
            ->assertJsonPath('data.flow_key', 'auth.login')
            ->assertJsonStructure(['data' => ['flow' => ['title', 'service', 'tables']]]);
    }

    public function test_admin_can_read_status_flows_and_user_card(): void
    {
        $admin = $this->seededUser('admin.test@bus-communal.local');
        Sanctum::actingAs($admin);

        $this->getJson('/api/admin/tooling/inspector/status')
            ->assertOk()
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.retention_days', 14);

        $this->getJson('/api/admin/tooling/inspector/flows')
            ->assertOk()
            ->assertJsonStructure(['data' => ['flows' => ['auth.login'], 'route_map']]);

        $this->getJson('/api/admin/tooling/inspector/flows/auth.login')
            ->assertOk()
            ->assertJsonPath('data.key', 'auth.login');

        $this->getJson('/api/admin/tooling/inspector/flows/unknown.flow')->assertNotFound();

        $this->getJson("/api/admin/tooling/inspector/users/{$admin->id}")
            ->assertOk()
            ->assertJsonPath('data.email', $admin->email)
            ->assertJsonStructure(['data' => ['roles', 'permissions', 'capabilities', 'inspector' => ['entries_count']]]);
    }

    public function test_admin_can_purge_entries_before_a_date(): void
    {
        $old = $this->makeEntry(['created_at' => now()->subDays(10)]);
        $recent = $this->makeEntry(['created_at' => now()]);

        Sanctum::actingAs($this->seededUser('admin.test@bus-communal.local'));

        $this->deleteJson('/api/admin/tooling/inspector/entries', ['before' => now()->subDay()->toDateTimeString()])
            ->assertOk()
            ->assertJsonPath('data.deleted', 1);

        $this->assertModelMissing($old);
        $this->assertModelExists($recent);
    }

    public function test_date_filters_cover_whole_brussels_days_in_summer_time(): void
    {
        Sanctum::actingAs($this->seededUser('admin.test@bus-communal.local'));
        // Le 3 octobre 2026 à Bruxelles (UTC+2) va du 2 octobre 22:00 au 3 octobre 21:59:59 UTC.
        $dayBefore = $this->makeEntry(['created_at' => '2026-10-02 21:59:59']);
        $morning = $this->makeEntry(['created_at' => '2026-10-02 22:00:00']);
        $evening = $this->makeEntry(['created_at' => '2026-10-03 21:59:59']);
        $dayAfter = $this->makeEntry(['created_at' => '2026-10-03 22:00:00']);

        $this->assertSame([$evening->id, $morning->id], $this->entryIds('from=2026-10-03&to=2026-10-03'));
        $this->assertSame([$evening->id, $morning->id, $dayBefore->id], $this->entryIds('to=2026-10-03'));
        $this->assertSame([$dayAfter->id, $evening->id, $morning->id], $this->entryIds('from=2026-10-03'));
        // Un horodatage complet est comparé tel quel.
        $this->assertSame([$dayBefore->id], $this->entryIds('to='.urlencode('2026-10-02 21:59:59')));
    }

    public function test_date_filters_use_brussels_midnight_in_winter_time(): void
    {
        Sanctum::actingAs($this->seededUser('admin.test@bus-communal.local'));
        // Le 15 janvier 2026 à Bruxelles (UTC+1) commence le 14 janvier à 23:00 UTC.
        $before = $this->makeEntry(['created_at' => '2026-01-14 22:59:59']);
        $inside = $this->makeEntry(['created_at' => '2026-01-14 23:00:00']);

        $this->assertSame([$inside->id], $this->entryIds('from=2026-01-15&to=2026-01-15'));

        $this->deleteJson('/api/admin/tooling/inspector/entries', ['before' => '2026-01-15'])
            ->assertOk()
            ->assertJsonPath('data.deleted', 1);
        $this->assertModelMissing($before);
        $this->assertModelExists($inside);
    }

    public function test_prune_command_deletes_entries_older_than_retention(): void
    {
        config(['tooling.inspector.retention_days' => 14]);
        $old = $this->makeEntry(['created_at' => now()->subDays(15)]);
        $recent = $this->makeEntry(['created_at' => now()->subDays(13)]);

        $this->artisan('tooling:inspector-prune')->assertSuccessful();

        $this->assertModelMissing($old);
        $this->assertModelExists($recent);
    }

    private function makeEntry(array $attributes = []): InspectorEntry
    {
        $entry = InspectorEntry::query()->create([
            'method' => 'POST',
            'path' => '/api/test',
            'http_status' => 200,
            'outcome' => 'success',
            ...collect($attributes)->except('created_at')->all(),
        ]);

        if (isset($attributes['created_at'])) {
            $entry->forceFill(['created_at' => $attributes['created_at']])->save();
        }

        return $entry;
    }

    /** @return list<int> */
    private function entryIds(string $query): array
    {
        return array_column($this->getJson('/api/admin/tooling/inspector/entries?'.$query)->assertOk()->json('data'), 'id');
    }

    private function seededUser(string $email): User
    {
        return User::query()->where('email', $email)->firstOrFail();
    }
}
