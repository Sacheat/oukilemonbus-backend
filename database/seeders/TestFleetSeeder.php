<?php

namespace Database\Seeders;

use App\Models\Driver;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class TestFleetSeeder extends Seeder
{
    public function run(): void
    {
        $chauffeur = User::query()->where('email', 'chauffeur.test@bus-communal.local')->firstOrFail();
        $secondDriverUser = User::query()->updateOrCreate(
            ['email' => 'driver.second@bus-communal.local'],
            [
                'first_name' => 'Paul',
                'last_name' => 'Second',
                'phone' => '082100007',
                'password' => Hash::make('password'),
            ]
        );

        Driver::query()->updateOrCreate(
            ['user_id' => $chauffeur->id],
            [
                'licence_number' => 'DRV-SEED-001',
                'licence_expiry_date' => '2030-01-01',
                'is_active' => true,
            ]
        );

        Driver::query()->updateOrCreate(
            ['licence_number' => 'DRV-SEED-002'],
            [
                'user_id' => $secondDriverUser->id,
                'licence_expiry_date' => '2030-01-01',
                'is_active' => true,
            ]
        );

        $vehicles = [
            [
                'licence_plate' => '1-VJX-649',
                'capacity' => 41,
                'is_active' => true,
            ],
            [
                'licence_plate' => 'BUS-SEED-002',
                'capacity' => 28,
                'is_active' => true,
            ],
        ];

        foreach ($vehicles as $attributes) {
            Vehicle::query()->updateOrCreate(
                ['licence_plate' => $attributes['licence_plate']],
                $attributes
            );
        }
    }
}
