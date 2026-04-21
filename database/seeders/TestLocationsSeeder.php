<?php

namespace Database\Seeders;

use App\Models\Location;
use Illuminate\Database\Seeder;

class TestLocationsSeeder extends Seeder
{
    public function run(): void
    {
        $locations = [
            [
                'formatted_address' => 'Rue Grande 1, Dinant',
                'street' => 'Rue Grande',
                'street_number' => '1',
                'postal_code' => '5500',
                'city' => 'Dinant',
                'country' => 'Belgique',
                'latitude' => 50.259200,
                'longitude' => 4.912000,
                'provider' => 'seed',
                'place_id' => 'seed-dinant-centre',
                'is_verified' => true,
            ],
            [
                'formatted_address' => 'Rue du Centre 1, Dinant',
                'street' => 'Rue du Centre',
                'street_number' => '1',
                'postal_code' => '5500',
                'city' => 'Dinant',
                'country' => 'Belgique',
                'latitude' => 50.260000,
                'longitude' => 4.910000,
                'provider' => 'seed',
                'place_id' => 'seed-dinant-pickup',
                'is_verified' => true,
            ],
            [
                'formatted_address' => 'Avenue des Sports 2, Dinant',
                'street' => 'Avenue des Sports',
                'street_number' => '2',
                'postal_code' => '5500',
                'city' => 'Dinant',
                'country' => 'Belgique',
                'latitude' => 50.270000,
                'longitude' => 4.920000,
                'provider' => 'seed',
                'place_id' => 'seed-dinant-destination',
                'is_verified' => true,
            ],
            [
                'formatted_address' => 'Place Baudouin 1, Anseremme',
                'street' => 'Place Baudouin',
                'street_number' => '1',
                'postal_code' => '5500',
                'city' => 'Anseremme',
                'country' => 'Belgique',
                'latitude' => 50.238700,
                'longitude' => 4.906300,
                'provider' => 'seed',
                'place_id' => 'seed-anseremme',
                'is_verified' => true,
            ],
            [
                'formatted_address' => '1 Rue de France, Givet',
                'street' => 'Rue de France',
                'street_number' => '1',
                'postal_code' => '08600',
                'city' => 'Givet',
                'country' => 'France',
                'latitude' => 50.137800,
                'longitude' => 4.824100,
                'provider' => 'seed',
                'place_id' => 'seed-givet',
                'is_verified' => true,
            ],
        ];

        foreach ($locations as $attributes) {
            Location::query()->updateOrCreate(
                ['place_id' => $attributes['place_id']],
                $attributes
            );
        }
    }
}
