<?php

namespace App\Services\Municipalities;

use App\Models\Municipality;
use App\Models\MunicipalityLocality;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MunicipalitySettingsService
{
    public function __construct(
        private readonly GeoNamesMunicipalityProvider $municipalityProvider
    ) {}

    public function create(array $data): Municipality
    {
        return DB::transaction(function () use ($data): Municipality {
            $municipality = Municipality::create([
                'name' => $data['name'],
                'external_id' => $data['external_id'] ?? null,
                'nis_code' => $data['nis_code'] ?? null,
                'country' => strtoupper($data['country'] ?? 'BE'),
                'postal_code' => $data['postal_code'] ?? null,
                'source' => $data['source'] ?? 'manual',
                'is_active' => $data['is_active'] ?? false,
            ]);

            if ($municipality->is_active) {
                $this->activate($municipality);
            }

            foreach ($data['localities'] ?? [] as $localityName) {
                $this->addLocality($municipality, [
                    'name' => $localityName,
                    'source' => $data['source'] ?? 'manual',
                    'is_active' => true,
                ]);
            }

            return $municipality->refresh();
        });
    }

    public function update(Municipality $municipality, array $data): Municipality
    {
        return DB::transaction(function () use ($municipality, $data): Municipality {
            $municipality->update(Arr::only([
                ...$data,
                'country' => array_key_exists('country', $data) ? strtoupper($data['country']) : $municipality->country,
            ], [
                'name',
                'external_id',
                'nis_code',
                'country',
                'postal_code',
                'source',
                'is_active',
            ]));

            if (($data['is_active'] ?? false) === true) {
                $this->activate($municipality);
            }

            return $municipality->refresh();
        });
    }

    public function addLocality(Municipality $municipality, array $data): MunicipalityLocality
    {
        return MunicipalityLocality::query()->updateOrCreate([
            'municipality_id' => $municipality->id,
            'normalized_name' => $this->normalize($data['name']),
        ], [
            'name' => $data['name'],
            'external_id' => $data['external_id'] ?? null,
            'source' => $data['source'] ?? 'manual',
            'is_active' => $data['is_active'] ?? true,
        ])->refresh();
    }

    public function updateLocality(MunicipalityLocality $locality, array $data): MunicipalityLocality
    {
        if (array_key_exists('name', $data)) {
            $normalizedName = $this->normalize($data['name']);

            $nameAlreadyExists = MunicipalityLocality::query()
                ->where('municipality_id', $locality->municipality_id)
                ->where('normalized_name', $normalizedName)
                ->whereKeyNot($locality->getKey())
                ->exists();

            if ($nameAlreadyExists) {
                throw ValidationException::withMessages([
                    'name' => 'Cette localite existe deja pour cette commune.',
                ]);
            }
        }

        $locality->update(Arr::only([
            ...$data,
            'normalized_name' => array_key_exists('name', $data)
                ? $this->normalize($data['name'])
                : $locality->normalized_name,
        ], [
            'name',
            'normalized_name',
            'external_id',
            'source',
            'is_active',
        ]));

        return $locality->refresh();
    }

    public function deactivateLocality(MunicipalityLocality $locality): MunicipalityLocality
    {
        $locality->update(['is_active' => false]);

        return $locality->refresh();
    }

    public function syncFromExternalProvider(Municipality $municipality): Municipality
    {
        return DB::transaction(function () use ($municipality): Municipality {
            $providerMunicipality = $this->municipalityProvider->findMunicipality($municipality);

            if ($providerMunicipality) {
                $municipality->update([
                    'external_id' => $providerMunicipality['geonameId'] ?? $municipality->external_id,
                    'source' => 'geonames',
                    'synced_at' => now(),
                ]);
            }

            $localities = $this->municipalityProvider->fetchLocalities($municipality->refresh());

            $syncedLocalityNames = $localities
                ->pluck('name')
                ->filter()
                ->map(fn (string $name): string => $this->normalize($name))
                ->all();

            foreach ($localities as $locality) {
                $this->addLocality($municipality, [
                    'name' => $locality['name'],
                    'external_id' => $locality['id'] ?? null,
                    'source' => 'geonames',
                    'is_active' => true,
                ]);
            }

            if ($localities->isNotEmpty()) {
                MunicipalityLocality::query()
                    ->where('municipality_id', $municipality->id)
                    ->where('source', 'geonames')
                    ->whereNotIn('normalized_name', $syncedLocalityNames)
                    ->update(['is_active' => false]);

                $municipality->update(['synced_at' => now()]);
            }

            return $municipality->refresh();
        });
    }

    public function normalize(string $value): string
    {
        return str($value)
            ->ascii()
            ->lower()
            ->trim()
            ->replaceMatches('/\s+/', ' ')
            ->toString();
    }

    private function activate(Municipality $municipality): void
    {
        Municipality::query()
            ->whereKeyNot($municipality->getKey())
            ->update(['is_active' => false]);

        $municipality->update(['is_active' => true]);
    }
}
