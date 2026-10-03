<?php

namespace Tests\Feature\Tooling;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TestUsersSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Jobs en échec de la file d'attente : /api/admin/tooling/queue/failed-jobs*.
 */
class FailedJobsApiTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/api/admin/tooling/queue/failed-jobs';

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->seed([RolesAndPermissionsSeeder::class, TestUsersSeeder::class]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Sanctum::actingAs($this->seededUser('admin.test@bus-communal.local'));
    }

    public function test_agent_is_forbidden(): void
    {
        $uuid = $this->makeFailedJob();
        Sanctum::actingAs($this->seededUser('agent.test@bus-communal.local'));

        $this->getJson(self::BASE)->assertForbidden();
        $this->postJson(self::BASE."/{$uuid}/retry")->assertForbidden();
        $this->deleteJson(self::BASE)->assertForbidden();

        $this->assertDatabaseHas('failed_jobs', ['uuid' => $uuid]);
    }

    public function test_list_is_paginated_most_recent_first_and_filterable_by_queue(): void
    {
        $old = $this->makeFailedJob(['queue' => 'low', 'failed_at' => '2026-10-01 08:00:00']);
        $recent = $this->makeFailedJob(['failed_at' => '2026-10-02 08:00:00', 'exception' => "RuntimeException: SMTP indisponible\n#0 /var/www/trace.php(12)"]);

        $this->getJson(self::BASE.'?per_page=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.uuid', $recent)
            ->assertJsonPath('data.0.job', 'App\\Notifications\\Auth\\QueuedResetPassword')
            ->assertJsonPath('data.0.exception_summary', 'RuntimeException: SMTP indisponible')
            ->assertJsonPath('data.0.failed_at', '2026-10-02T08:00:00.000000Z')
            ->assertJsonPath('links.next', fn (string $url) => str_contains($url, 'per_page=1'));

        $this->getJson(self::BASE.'?queue=low')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.uuid', $old);
    }

    public function test_detail_masks_the_serialized_command_and_sensitive_keys(): void
    {
        $uuid = $this->makeFailedJob();

        $response = $this->getJson(self::BASE."/{$uuid}")
            ->assertOk()
            ->assertJsonPath('data.uuid', $uuid)
            ->assertJsonPath('data.payload.data.command', '[objet sérialisé masqué]')
            ->assertJsonPath('data.payload.data.commandName', 'Illuminate\\Notifications\\SendQueuedNotifications')
            ->assertJsonPath('data.payload.reset_token', '[masqué]')
            ->assertJsonPath('data.exception', "RuntimeException: SMTP indisponible\n#0 trace");

        $this->assertStringNotContainsString('jeton-secret', $response->getContent());

        $this->getJson(self::BASE.'/'.Str::uuid())->assertNotFound();
    }

    public function test_retry_puts_the_job_back_on_its_queue(): void
    {
        $uuid = $this->makeFailedJob(['queue' => 'critical']);

        $this->postJson(self::BASE."/{$uuid}/retry")
            ->assertAccepted()
            ->assertJsonPath('data.retried', 1);

        $this->assertDatabaseMissing('failed_jobs', ['uuid' => $uuid]);
        $this->assertSame(1, DB::table('jobs')->where('queue', 'critical')->where('payload', 'like', "%{$uuid}%")->count());
    }

    public function test_retry_all(): void
    {
        $this->makeFailedJob();
        $this->makeFailedJob();

        $this->postJson(self::BASE.'/retry')->assertAccepted()->assertJsonPath('data.retried', 2);

        $this->assertSame(0, DB::table('failed_jobs')->count());
        $this->assertSame(2, DB::table('jobs')->count());
    }

    public function test_delete_one_and_delete_before_a_brussels_day(): void
    {
        $one = $this->makeFailedJob();
        // 2 octobre à Bruxelles (heure d'été) commence le 1er octobre à 22:00 UTC.
        $beforeDay = $this->makeFailedJob(['failed_at' => '2026-10-01 21:59:59']);
        $sameDay = $this->makeFailedJob(['failed_at' => '2026-10-01 22:00:00']);

        $this->deleteJson(self::BASE."/{$one}")->assertOk()->assertJsonPath('data.deleted', 1);
        $this->deleteJson(self::BASE.'/'.Str::uuid())->assertNotFound();

        $this->deleteJson(self::BASE, ['before' => '2026-10-02'])->assertOk()->assertJsonPath('data.deleted', 1);
        $this->assertDatabaseMissing('failed_jobs', ['uuid' => $beforeDay]);
        $this->assertDatabaseHas('failed_jobs', ['uuid' => $sameDay]);

        $this->deleteJson(self::BASE)->assertOk()->assertJsonPath('data.deleted', 1);
        $this->assertSame(0, DB::table('failed_jobs')->count());
    }

    private function makeFailedJob(array $attributes = []): string
    {
        $uuid = (string) Str::uuid();

        DB::table('failed_jobs')->insert([
            'uuid' => $uuid,
            'connection' => 'database',
            'queue' => 'default',
            'payload' => json_encode([
                'uuid' => $uuid,
                'displayName' => 'App\\Notifications\\Auth\\QueuedResetPassword',
                'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
                'attempts' => 3,
                'reset_token' => 'jeton-secret',
                'data' => [
                    'commandName' => 'Illuminate\\Notifications\\SendQueuedNotifications',
                    'command' => serialize((object) ['token' => 'jeton-secret']),
                ],
            ]),
            'exception' => "RuntimeException: SMTP indisponible\n#0 trace",
            'failed_at' => '2026-10-02 08:00:00',
            ...$attributes,
        ]);

        return $uuid;
    }

    private function seededUser(string $email): User
    {
        return User::query()->where('email', $email)->firstOrFail();
    }
}
