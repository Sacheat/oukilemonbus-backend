<?php

namespace Tests\Feature;

use App\Enums\ReservationStatus;
use App\Models\DriverAlert;
use App\Models\Organization;
use App\Models\OrgRegistrationRequest;
use App\Models\Reservation;
use App\Models\User;
use App\Models\UserOrgRequest;
use App\Models\Vehicle;
use Database\Seeders\DemoDataSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TestFleetSeeder;
use Database\Seeders\TestLocationsSeeder;
use Database\Seeders\TestOrganizationsSeeder;
use Database\Seeders\TestUsersSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Données de démonstration (DemoDataSeeder) : relançables à l'identique, et retirées sans toucher au reste.
 * Heure fixée en journée (mardi 16 juin 2026, 14:00 à Bruxelles) : les trajets du jour se placent autour.
 */
class DemoDataSeederTest extends TestCase
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

        Carbon::setTestNow('2026-06-16 12:00:00');
        Notification::fake();
        Storage::fake('local');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_the_demo_covers_every_case_is_idempotent_and_clears_itself(): void
    {
        $usersBefore = User::query()->where('email', 'like', '%.test@bus-communal.local')->count();

        $this->seed(DemoDataSeeder::class);
        $demo = fn () => Reservation::query()->where('trip_purpose', 'like', DemoDataSeeder::PREFIX.'%');
        $count = $demo()->count();

        // Couverture et volume
        $this->assertGreaterThan(200, $count);
        foreach ([ReservationStatus::EN_ATTENTE, ReservationStatus::EN_CONFLIT, ReservationStatus::APPROUVE, ReservationStatus::CONFIRME, ReservationStatus::REFUSE, ReservationStatus::ANNULE] as $status) {
            $this->assertTrue($demo()->where('status', $status->value)->exists(), "Aucune réservation de démo {$status->value}");
        }
        // Collège défavorable (vrai service) : la réservation est refusée
        $this->assertSame(ReservationStatus::REFUSE, $demo()->where('trip_purpose', 'like', '%Collège défavorable%')->firstOrFail()->status);
        // Quota de l'ASBL presque atteint, organisation externe bannie
        $this->assertTrue(Organization::query()->where('name', DemoDataSeeder::PREFIX.' Société Ardennes Tours')->firstOrFail()->is_banned);
        $this->assertSame(3, DriverAlert::query()->count());
        $this->assertSame(3, UserOrgRequest::query()->where('status', 'en_attente')->whereHas('organization', fn ($q) => $q->where('name', 'like', DemoDataSeeder::PREFIX.'%'))->count());
        $this->assertTrue(OrgRegistrationRequest::query()->where('organization_name', 'like', DemoDataSeeder::PREFIX.'%')->whereNotNull('proof_document_id')->exists());

        // Relancer remplace la démo à l'identique
        $this->seed(DemoDataSeeder::class);
        $this->assertSame($count, $demo()->count());

        // Nettoyage : plus rien de la démo, les comptes de test restent
        app(DemoDataSeeder::class)->clear();
        $this->assertSame(0, $demo()->count());
        $this->assertSame(0, User::query()->where('email', 'like', 'demo.%@bus-communal.local')->count());
        $this->assertSame(0, Organization::query()->where('name', 'like', DemoDataSeeder::PREFIX.'%')->count());
        $this->assertSame(0, Vehicle::query()->where('licence_plate', 'like', 'DEMO-%')->count());
        $this->assertSame(0, DriverAlert::query()->count());
        $this->assertSame($usersBefore, User::query()->where('email', 'like', '%.test@bus-communal.local')->count());
    }
}
