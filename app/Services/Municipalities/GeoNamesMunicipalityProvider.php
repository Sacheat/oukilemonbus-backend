<?php

namespace App\Services\Municipalities;

use App\Models\Municipality;
use Illuminate\Support\Collection;

class GeoNamesMunicipalityProvider
{
    public function __construct(
        private readonly GeoNamesClient $client
    ) {}

    public function findMunicipality(Municipality $municipality): ?array
    {
        $items = $this->client->get('searchJSON', [
            'name_equals' => $municipality->name,
            'country' => $municipality->country ?: 'BE',
            'featureClass' => 'A',
            'maxRows' => 1,
            'style' => 'FULL',
            'lang' => 'fr',
        ])['geonames'] ?? [];

        return $items[0] ?? null;
    }

    public function fetchLocalities(Municipality $municipality): Collection
    {
        $localities = collect();

        foreach ($this->postalCodeQueries($municipality) as $query) {
            $items = $this->client->get('postalCodeSearchJSON', [
                ...$query,
                'country' => $municipality->country ?: 'BE',
                'maxRows' => (int) config('services.geonames.max_rows', 100),
                'style' => 'FULL',
                'lang' => 'fr',
            ])['postalCodes'] ?? [];

            foreach ($items as $item) {
                $locality = $this->extractLocality($item);

                if ($locality) {
                    $localities->put($locality['name'], $locality);
                }
            }
        }

        if ($localities->isEmpty()) {
            $municipalityResult = $this->findMunicipality($municipality);

            if ($municipalityResult) {
                $localities->put($municipalityResult['name'], [
                    'id' => $municipalityResult['geonameId'] ?? null,
                    'name' => $municipalityResult['name'],
                ]);
            }
        }

        return $localities->values();
    }

    private function postalCodeQueries(Municipality $municipality): array
    {
        $queries = [];

        if (filled($municipality->postal_code)) {
            $queries[] = [
                'postalcode_startsWith' => substr($municipality->postal_code, 0, 3),
            ];
        } else {
            $queries[] = ['placename' => $municipality->name];
        }

        return $queries;
    }

    private function extractLocality(array $item): ?array
    {
        $name = $item['placeName'] ?? $item['adminName3'] ?? null;

        if (blank($name)) {
            return null;
        }

        return [
            'id' => $item['geonameId'] ?? null,
            'name' => $name,
        ];
    }
}
