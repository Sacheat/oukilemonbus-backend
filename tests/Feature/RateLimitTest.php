<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TestUsersSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Limiteurs nommés (config/rate_limits.php) : chaque famille a son compteur. Avant, tous les throttle:N,1 partageaient
 * un seul compteur par utilisateur, et 20 consultations suffisaient à bloquer un preview.
 */
class RateLimitTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->seed([RolesAndPermissionsSeeder::class, TestUsersSeeder::class]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs(User::query()->where('email', 'responsable.test@bus-communal.local')->firstOrFail());
    }

    public function test_reading_pages_does_not_use_up_the_preview_limit(): void
    {
        foreach (range(1, 25) as $request) {
            $this->getJson('/api/reservations')->assertOk();
        }

        // Plus de 20 consultations : le preview (limite 20) répond encore (422 de validation, pas 429)
        $this->postJson('/api/reservations/preview', [])->assertUnprocessable();
    }

    public function test_each_family_stops_at_its_own_limit(): void
    {
        config(['rate_limits.consultation' => 3, 'rate_limits.action' => 2]);

        foreach (range(1, 3) as $request) {
            $this->getJson('/api/reservations')->assertOk();
        }
        $this->getJson('/api/reservations')->assertTooManyRequests();
        // Les consultations sont partagées : le planning est lui aussi bloqué
        $this->getJson('/api/planning?start_date=2026-06-15&end_date=2026-06-19')->assertTooManyRequests();

        // Les actions ont leur propre compteur, route par route
        $this->postJson('/api/reservations/preview', [])->assertUnprocessable();
        $this->postJson('/api/reservations/preview', [])->assertUnprocessable();
        $this->postJson('/api/reservations/preview', [])->assertTooManyRequests();
        $this->postJson('/api/recurrences/preview', [])->assertStatus(422);
    }

    public function test_the_default_reading_limit_is_300_per_minute(): void
    {
        $this->getJson('/api/reservations')
            ->assertOk()
            ->assertHeader('X-RateLimit-Limit', '300');
    }
}
