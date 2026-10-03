<?php

namespace Tests\Feature\Tooling;

use App\Models\User;
use App\Tooling\Inspector\Models\InspectorEntry;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TestUsersSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Chaque route mutante de l'API (POST, PUT, PATCH, DELETE) doit avoir un flow dans
 * tooling.inspector.route_map, sinon elle arrive dans le journal avec flow_key = null.
 */
class InspectorRouteMapTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_mutating_api_route_is_mapped_to_a_known_flow(): void
    {
        $routeMap = config('tooling.inspector.route_map', []);
        $flows = config('tooling.inspector.flows', []);
        $ignored = (array) config('tooling.inspector.ignored_routes', []);

        $unmapped = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route): bool => str_starts_with((string) $route->getName(), 'api.')
                && array_intersect($route->methods(), ['POST', 'PUT', 'PATCH', 'DELETE']) !== []
                && ! Str::is($ignored, $route->getName()))
            ->map(fn ($route): string => $route->getName())
            ->reject(fn (string $name): bool => isset($routeMap[$name]))
            ->values()
            ->all();

        $this->assertSame([], $unmapped, 'Routes mutantes sans flow dans tooling.inspector.route_map.');

        $unknownFlows = array_keys(array_filter($routeMap, fn (string $flowKey): bool => ! isset($flows[$flowKey])));
        $this->assertSame([], $unknownFlows, 'route_map pointe vers des flows absents de tooling.inspector.flows.');
    }

    public function test_password_reset_link_is_recorded_with_its_flow(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->seed([RolesAndPermissionsSeeder::class, TestUsersSeeder::class]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        config(['tooling.inspector.enabled' => true]);
        Notification::fake();

        Sanctum::actingAs(User::query()->where('email', 'admin.test@bus-communal.local')->firstOrFail());
        $target = User::query()->where('email', 'user.test@bus-communal.local')->firstOrFail();

        $this->postJson("/api/users/{$target->id}/password-reset-link")->assertAccepted();

        $this->assertSame('admin.users.send_password_reset_link', InspectorEntry::query()->sole()->flow_key);
    }
}
