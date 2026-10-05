<?php

namespace App\Services\Municipalities;

use App\Models\Municipality;
use App\Models\MunicipalityLocality;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Gère les paramètres des communes et la synchronisation des localités depuis GeoNames.
 *
 * Une seule commune peut être `is_active = true` à la fois : `activate()` désactive toutes
 * les autres avant d'activer la cible (règle de singletons enforced en DB).
 *
 * `addLocality()` utilise updateOrCreate sur `normalized_name` pour éviter les doublons
 * lors de la synchronisation. La normalisation est commune avec MunicipalityService.
 *
 * `syncFromExternalProvider()` :
 *   1. Met à jour l'external_id, le source et le centre (latitude/longitude) de la commune depuis GeoNames.
 *   2. Récupère toutes les sous-localités via GeoNamesMunicipalityProvider.
 *   3. Ajoute / réactive celles reçues.
 *   4. Désactive (soft) les localités source='geonames' absentes de la liste retournée.
 *   L'entité GeoNames est passée en paramètre à fetchLocalities() pour éviter un second appel API.
 */
class MunicipalitySettingsService
{
    /** Point de référence et limites de distance appliqués aux réservations. */
    private const REFERENCE_FIELDS = ['latitude', 'longitude', 'depot_location_id', 'max_trip_distance_km', 'max_pickup_distance_km'];

    /** Contact de garde, prévenu par email des demandes d'aide des chauffeurs (DriverAlertService). */
    private const ON_CALL_FIELDS = ['on_call_name', 'on_call_email', 'on_call_phone'];

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
                ...Arr::only($data, self::REFERENCE_FIELDS),
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
                ...self::REFERENCE_FIELDS,
                ...self::ON_CALL_FIELDS,
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
                    'name' => 'Cette localité existe déjà pour cette commune.',
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
                    // Centre de la commune : point de référence de repli quand aucun dépôt n'est défini.
                    'latitude' => $providerMunicipality['lat'] ?? $municipality->latitude,
                    'longitude' => $providerMunicipality['lng'] ?? $municipality->longitude,
                ]);
            }

            // L'entité est passée directement pour éviter un second appel API.
            $localities = $this->municipalityProvider->fetchLocalities($municipality->refresh(), $providerMunicipality);

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
