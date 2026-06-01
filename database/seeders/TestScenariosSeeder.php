<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class TestScenariosSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            TestUsersSeeder::class,
            TestLocationsSeeder::class,
            TestMunicipalitySeeder::class,
            TestOrganizationsSeeder::class,
            TestFleetSeeder::class,
            TestReservationsSeeder::class,
            TestRequestsSeeder::class,
            TestUnavailabilitiesSeeder::class,
        ]);
    }
}
