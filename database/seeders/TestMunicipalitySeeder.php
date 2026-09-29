<?php

namespace Database\Seeders;

use App\Models\Municipality;
use App\Models\MunicipalityLocality;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class TestMunicipalitySeeder extends Seeder
{
    public function run(): void
    {
        $municipality = Municipality::query()->updateOrCreate(
            ['name' => 'Dinant'],
            [
                'external_id' => 'seed-dinant',
                'nis_code' => '91034',
                'country' => 'BE',
                'postal_code' => '5500',
                // Centre de Dinant : point de référence tant qu'aucun dépôt n'est défini.
                'latitude' => 50.26060000,
                'longitude' => 4.91220000,
                'source' => 'manual',
                'is_active' => true,
                'synced_at' => now(),
            ]
        );

        foreach (['Dinant', 'Anseremme', 'Bouvignes-sur-Meuse'] as $locality) {
            MunicipalityLocality::query()->updateOrCreate(
                [
                    'municipality_id' => $municipality->id,
                    'normalized_name' => Str::of($locality)->ascii()->lower()->replace(' ', '-')->value(),
                ],
                [
                    'name' => $locality,
                    'external_id' => 'seed-'.Str::slug($locality),
                    'source' => 'manual',
                    'is_active' => true,
                ]
            );
        }
    }
}
