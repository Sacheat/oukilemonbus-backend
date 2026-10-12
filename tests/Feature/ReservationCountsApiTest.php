<?php

namespace Tests\Feature;

use App\Enums\ApprovalType;
use App\Enums\OrganizationType;
use App\Enums\ReservationStatus;
use App\Models\Driver;
use App\Models\Location;
use App\Models\Organization;
use App\Models\Reservation;
use App\Models\User;
use App\Models\Vehicle;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TestFleetSeeder;
use Database\Seeders\TestLocationsSeeder;
use Database\Seeders\TestOrganizationsSeeder;
use Database\Seeders\TestUsersSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * GET /reservations/counts : plusieurs compteurs en un appel, toujours égaux au meta.total de la liste filtrée pareil.
 */
class ReservationCountsApiTest extends TestCase
{
    use DatabaseTransactions;

    /** Période isolée : seules les réservations de ce test s'y trouvent. */
    private const PERIOD = 'date_from=2027-03-01&date_to=2027-03-31';

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

    public function test_each_count_matches_the_total_of_the_same_filtered_list(): void
    {
        $this->reservation('2027-03-02', ReservationStatus::EN_ATTENTE, ['trip_purpose' => 'Piscine']);
        $this->reservation('2027-03-03', ReservationStatus::EN_ATTENTE);
        $this->reservation('2027-03-04', ReservationStatus::EN_CONFLIT);
        $this->reservation('2027-03-05', ReservationStatus::APPROUVE); // sans véhicule : à affecter
        $this->reservation('2027-03-06', ReservationStatus::APPROUVE, ['vehicle_id' => $this->vehicle()->id]);
        $this->reservation('2027-03-07', ReservationStatus::CONFIRME, ['vehicle_id' => $this->vehicle()->id]);
        $this->reservation('2027-04-02', ReservationStatus::EN_ATTENTE); // hors période
        Sanctum::actingAs($this->user('agent.test@bus-communal.local'));

        $counters = [
            'a-traiter' => 'statuses[]=en_attente&statuses[]=en_conflit',
            'a-traiter:en-attente' => 'statuses[]=en_attente',
            'a-affecter' => 'statuses[]=approuve&unassigned=1',
            'confirmees' => 'statuses[]=confirme',
        ];
        $query = collect($counters)
            ->map(fn (string $filters, string $name) => preg_replace('/(^|&)([a-z_]+)/', '$1counts['.$name.'][$2]', $filters))
            ->implode('&');

        $counts = $this->getJson('/api/reservations/counts?'.self::PERIOD.'&'.$query)->assertOk()->json('data');

        $this->assertSame(['a-traiter' => 3, 'a-traiter:en-attente' => 2, 'a-affecter' => 1, 'confirmees' => 1], $counts);
        foreach ($counters as $name => $filters) {
            $total = $this->getJson('/api/reservations?'.self::PERIOD.'&'.$filters)->assertOk()->json('meta.total');
            $this->assertSame($total, $counts[$name], "Compteur {$name} différent de la liste");
        }

        // Les filtres communs s'appliquent à chaque compteur (recherche libre)
        $this->getJson('/api/reservations/counts?'.self::PERIOD.'&q=piscine&counts[a-traiter][statuses][]=en_attente')
            ->assertOk()
            ->assertExactJson(['data' => ['a-traiter' => 1]]);
    }

    public function test_counts_only_include_reservations_the_user_may_see(): void
    {
        $this->reservation('2027-03-02', ReservationStatus::EN_ATTENTE);
        $this->reservation('2027-03-03', ReservationStatus::EN_ATTENTE, [
            'requested_by_user_id' => $this->user('responsable.test@bus-communal.local')->id,
            'organization_id' => Organization::query()->where('type', '!=', OrganizationType::ECOLE_COMMUNALE)->orderBy('id')->firstOrFail()->id,
        ]);
        $user = $this->user('user.test@bus-communal.local');
        Sanctum::actingAs($user);

        $visible = $this->getJson('/api/reservations?'.self::PERIOD.'&statuses[]=en_attente')->assertOk()->json('meta.total');

        $this->getJson('/api/reservations/counts?'.self::PERIOD.'&counts[tous][statuses][]=en_attente')
            ->assertOk()
            ->assertJsonPath('data.tous', $visible);
        $this->assertLessThan(2, $visible);
    }

    public function test_invalid_counters_are_refused(): void
    {
        Sanctum::actingAs($this->user('agent.test@bus-communal.local'));

        $this->getJson('/api/reservations/counts')->assertUnprocessable()->assertJsonValidationErrors('counts');
        $this->getJson('/api/reservations/counts?counts[A%20B][statuses][]=en_attente')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('counts');
        $this->getJson('/api/reservations/counts?counts[x][statuses][]=inconnu')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('counts.x.statuses.0');
        $this->getJson('/api/reservations/counts?counts[x][council]=oui')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('counts.x.council');

        $tooMany = collect(range(1, 13))->map(fn (int $index) => "counts[c{$index}][statuses][]=en_attente")->implode('&');
        $this->getJson('/api/reservations/counts?'.$tooMany)->assertUnprocessable()->assertJsonValidationErrors('counts');
    }

    private function reservation(string $tripDate, ReservationStatus $status, array $attributes = []): Reservation
    {
        return Reservation::create([
            'requested_by_user_id' => $this->user('user.test@bus-communal.local')->id,
            'organization_id' => Organization::query()->where('type', OrganizationType::ECOLE_COMMUNALE)->orderBy('id')->firstOrFail()->id,
            'pickup_location_id' => Location::query()->where('place_id', 'seed-dinant-pickup')->firstOrFail()->id,
            'destination_location_id' => Location::query()->where('place_id', 'seed-dinant-destination')->firstOrFail()->id,
            'driver_id' => Driver::query()->where('licence_number', 'DRV-SEED-001')->firstOrFail()->id,
            'vehicle_id' => null,
            'trip_date' => $tripDate,
            'departure_time' => '09:00:00',
            'return_time' => '11:00:00',
            'nb_children' => 10,
            'nb_adults' => 1,
            'trip_purpose' => 'Sortie',
            'estimated_duration_min' => 20,
            'status' => $status,
            'approval_type' => ApprovalType::AUTO,
            ...$attributes,
        ]);
    }

    private function vehicle(): Vehicle
    {
        return Vehicle::query()->where('licence_plate', '1-VJX-649')->firstOrFail();
    }

    private function user(string $email): User
    {
        return User::query()->where('email', $email)->firstOrFail();
    }
}
