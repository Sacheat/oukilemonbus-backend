<?php

namespace Tests\Feature;

use App\Enums\ApprovalType;
use App\Enums\OrganizationType;
use App\Enums\ReservationStatus;
use App\Models\Location;
use App\Models\Organization;
use App\Models\Reservation;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TestFleetSeeder;
use Database\Seeders\TestLocationsSeeder;
use Database\Seeders\TestOrganizationsSeeder;
use Database\Seeders\TestUsersSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Délais d'annulation calculés à l'heure de Bruxelles (l'application tourne en UTC).
 * Trajet : mercredi 17 juin 2026 à 09:00, heure de Bruxelles (07:00 UTC, heure d'été).
 */
class ReservationCancellationDeadlineTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->seed([
            RolesAndPermissionsSeeder::class,
            TestUsersSeeder::class,
            TestLocationsSeeder::class,
            TestOrganizationsSeeder::class,
            TestFleetSeeder::class,
        ]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_requester_can_cancel_until_24_hours_before_departure_in_brussels(): void
    {
        $reservation = $this->reservation();
        Sanctum::actingAs($this->user('user.test@bus-communal.local'));

        // 09:30 à Bruxelles le 16/06 : plus que 23h30 avant le départ, trop tard
        Carbon::setTestNow('2026-06-16 07:30:00');
        $this->patchJson("/api/reservations/{$reservation->id}/cancel", ['reason' => 'Sortie annulée'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reservation');

        // 08:30 à Bruxelles le 16/06 : encore 24h30 avant le départ
        Carbon::setTestNow('2026-06-16 06:30:00');
        $this->patchJson("/api/reservations/{$reservation->id}/cancel", ['reason' => 'Sortie annulée'])
            ->assertOk();

        $this->assertSame(ReservationStatus::ANNULE, $reservation->refresh()->status);
    }

    public function test_commune_can_cancel_until_the_day_before_in_brussels(): void
    {
        $reservation = $this->reservation();
        Sanctum::actingAs($this->user('agent.test@bus-communal.local'));

        // 00:30 à Bruxelles le 17/06 (22:30 UTC le 16) : c'est déjà le jour du trajet
        Carbon::setTestNow('2026-06-16 22:30:00');
        $this->patchJson("/api/reservations/{$reservation->id}/cancel", ['reason' => 'Bus indisponible'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reservation');

        // 23:30 à Bruxelles le 16/06 : encore la veille
        Carbon::setTestNow('2026-06-16 21:30:00');
        $this->patchJson("/api/reservations/{$reservation->id}/cancel", ['reason' => 'Bus indisponible'])
            ->assertOk();

        $this->assertSame(ReservationStatus::ANNULE, $reservation->refresh()->status);
    }

    public function test_departure_at_is_the_brussels_wall_time(): void
    {
        $this->assertSame('2026-06-17T07:00:00+00:00', $this->reservation()->departureAt()->utc()->toIso8601String());
    }

    private function reservation(): Reservation
    {
        return Reservation::create([
            'requested_by_user_id' => $this->user('user.test@bus-communal.local')->id,
            'organization_id' => Organization::query()->where('type', OrganizationType::ECOLE_COMMUNALE)->orderBy('id')->firstOrFail()->id,
            'pickup_location_id' => Location::query()->where('place_id', 'seed-dinant-pickup')->firstOrFail()->id,
            'destination_location_id' => Location::query()->where('place_id', 'seed-dinant-destination')->firstOrFail()->id,
            'trip_date' => '2026-06-17',
            'departure_time' => '09:00:00',
            'return_time' => '11:00:00',
            'nb_children' => 10,
            'nb_adults' => 1,
            'trip_purpose' => 'Sortie',
            'estimated_duration_min' => 20,
            'status' => ReservationStatus::APPROUVE,
            'approval_type' => ApprovalType::AUTO,
        ]);
    }

    private function user(string $email): User
    {
        return User::query()->where('email', $email)->firstOrFail();
    }
}
