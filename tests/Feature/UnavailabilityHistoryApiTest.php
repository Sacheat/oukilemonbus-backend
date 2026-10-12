<?php

namespace Tests\Feature;

use App\Models\Driver;
use App\Models\Unavailability;
use App\Models\UnavailabilityHistory;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TestFleetSeeder;
use Database\Seeders\TestUnavailabilitiesSeeder;
use Database\Seeders\TestUsersSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class UnavailabilityHistoryApiTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->seed([
            RolesAndPermissionsSeeder::class,
            TestUsersSeeder::class,
            TestFleetSeeder::class,
            TestUnavailabilitiesSeeder::class,
        ]);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function createUnavailability(User $agent): Unavailability
    {
        $user = User::factory()->create();
        $driver = Driver::create([
            'user_id' => $user->id,
            'licence_number' => 'B-'.str_pad((string) (Driver::count() + 1), 6, '0', STR_PAD_LEFT),
            'licence_expiry_date' => '2030-01-01',
        ]);

        return Unavailability::create([
            'reason_type' => 'driver',
            'driver_id' => $driver->id,
            'start_datetime' => '2026-06-10 08:00:00',
            'end_datetime' => '2026-06-10 18:00:00',
            'created_by_user_id' => $agent->id,
        ]);
    }

    public function test_guest_cannot_access_history_routes(): void
    {
        $this->getJson('/api/unavailabilities/1/history')->assertUnauthorized();
    }

    public function test_update_creates_history_entry(): void
    {
        $agent = $this->seededUser('agent.test@bus-communal.local');
        Sanctum::actingAs($agent);

        $unavailability = $this->createUnavailability($agent);

        $this->patchJson("/api/unavailabilities/{$unavailability->id}", [
            'end_datetime' => '2026-06-11 18:00:00',
        ])->assertOk();

        $this->assertDatabaseHas('unavailabilities_history', [
            'unavailability_id' => $unavailability->id,
            'changed_by_user_id' => $agent->id,
            'old_end_datetime' => '2026-06-10 18:00:00',
            'new_end_datetime' => '2026-06-11 18:00:00',
        ]);
    }

    public function test_update_can_store_change_reason(): void
    {
        $agent = $this->seededUser('agent.test@bus-communal.local');
        Sanctum::actingAs($agent);

        $unavailability = $this->createUnavailability($agent);

        $this->patchJson("/api/unavailabilities/{$unavailability->id}", [
            'end_datetime' => '2026-06-11 18:00:00',
            'change_reason' => 'Prolongation confirmee par telephone',
        ])->assertOk();

        $this->assertDatabaseHas('unavailabilities_history', [
            'unavailability_id' => $unavailability->id,
            'changed_by_user_id' => $agent->id,
            'change_reason' => 'Prolongation confirmee par telephone',
        ]);
    }

    public function test_cancel_creates_history_entry(): void
    {
        $agent = $this->seededUser('agent.test@bus-communal.local');
        Sanctum::actingAs($agent);

        $unavailability = $this->createUnavailability($agent);

        $this->patchJson("/api/unavailabilities/{$unavailability->id}/cancel")->assertOk();

        $this->assertDatabaseHas('unavailabilities_history', [
            'unavailability_id' => $unavailability->id,
            'changed_by_user_id' => $agent->id,
            'change_reason' => 'Indisponibilite annulee.',
        ]);
    }

    public function test_agent_can_list_history(): void
    {
        $agent = $this->seededUser('agent.test@bus-communal.local');
        Sanctum::actingAs($agent);

        $unavailability = $this->createUnavailability($agent);

        UnavailabilityHistory::create([
            'unavailability_id' => $unavailability->id,
            'changed_by_user_id' => $agent->id,
            'change_reason' => 'Test historique',
        ]);

        $this->getJson("/api/unavailabilities/{$unavailability->id}/history")
            ->assertOk()
            ->assertJsonPath('data.0.unavailability_id', $unavailability->id)
            ->assertJsonPath('data.0.changed_by.id', $agent->id)
            ->assertJsonPath('data.0.changed_by.name', trim($agent->first_name.' '.$agent->last_name));
    }

    public function test_agent_can_show_history_entry(): void
    {
        $agent = $this->seededUser('agent.test@bus-communal.local');
        Sanctum::actingAs($agent);

        $unavailability = $this->createUnavailability($agent);

        $history = UnavailabilityHistory::create([
            'unavailability_id' => $unavailability->id,
            'changed_by_user_id' => $agent->id,
            'change_reason' => 'Test historique',
        ]);

        $this->getJson("/api/unavailabilities/{$unavailability->id}/history/{$history->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $history->id)
            ->assertJsonPath('data.changed_by.id', $agent->id);
    }

    public function test_agent_cannot_show_history_entry_from_another_unavailability(): void
    {
        $agent = $this->seededUser('agent.test@bus-communal.local');
        Sanctum::actingAs($agent);

        $firstUnavailability = $this->createUnavailability($agent);
        $secondUnavailability = $this->createUnavailability($agent);

        $history = UnavailabilityHistory::create([
            'unavailability_id' => $secondUnavailability->id,
            'changed_by_user_id' => $agent->id,
            'change_reason' => 'Historique separe',
        ]);

        $this->getJson("/api/unavailabilities/{$firstUnavailability->id}/history/{$history->id}")
            ->assertNotFound();
    }

    private function seededUser(string $email): User
    {
        return User::query()->where('email', $email)->firstOrFail();
    }
}
