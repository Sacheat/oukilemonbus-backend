<?php

namespace Tests\Feature\Tooling;

use App\Models\User;
use App\Services\SystemHealthService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TestUsersSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Mockery\MockInterface;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ToolboxApiTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->seed([RolesAndPermissionsSeeder::class, TestUsersSeeder::class]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_guest_and_non_admin_cannot_access_toolbox(): void
    {
        $this->getJson('/api/admin/tooling/toolbox/overview')->assertUnauthorized();

        Sanctum::actingAs($this->seededUser('agent.test@bus-communal.local'));

        $this->getJson('/api/admin/tooling/toolbox/overview')->assertForbidden();
    }

    public function test_admin_can_read_toolbox_sections(): void
    {
        Sanctum::actingAs($this->seededUser('admin.test@bus-communal.local'));

        $this->getJson('/api/admin/tooling/toolbox/overview')
            ->assertOk()
            ->assertJsonStructure(['data' => ['db_stats', 'business_rules', 'architecture']]);

        $this->getJson('/api/admin/tooling/toolbox/permissions')
            ->assertOk()
            ->assertJsonStructure(['data' => ['roles', 'permissions', 'matrix']])
            ->assertJsonFragment(['tooling.access']);

        $this->getJson('/api/admin/tooling/toolbox/users')
            ->assertOk()
            ->assertJsonStructure(['data' => ['Admin']]);

        $catalog = $this->getJson('/api/admin/tooling/toolbox/api-catalog')
            ->assertOk()
            ->assertJsonStructure(['data' => ['internal', 'external']])
            ->json('data.internal');

        $this->assertStringNotContainsString('admin/tooling', json_encode($catalog));
    }

    public function test_admin_can_read_system_health(): void
    {
        $this->mock(SystemHealthService::class, function (MockInterface $mock): void {
            $checks = ['database' => ['status' => 'ok']];
            $mock->shouldReceive('check')->once()->andReturn($checks);
            $mock->shouldReceive('isHealthy')->once()->with($checks)->andReturn(true);
        });

        Sanctum::actingAs($this->seededUser('admin.test@bus-communal.local'));

        $this->getJson('/api/admin/tooling/toolbox/health')
            ->assertOk()
            ->assertJsonPath('data.healthy', true)
            ->assertJsonPath('data.checks.database.status', 'ok');
    }

    public function test_dev_toolbox_command_is_blocked_when_disabled(): void
    {
        config(['tooling.toolbox.enabled' => false]);

        $this->artisan('dev:toolbox')->assertFailed();
    }

    private function seededUser(string $email): User
    {
        return User::query()->where('email', $email)->firstOrFail();
    }
}
