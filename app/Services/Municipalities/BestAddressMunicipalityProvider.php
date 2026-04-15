<?php

namespace App\Services\Municipalities;

use App\Models\Municipality;
use Illuminate\Support\Collection;

class BestAddressMunicipalityProvider
{
    public function __construct(
        private readonly BestAddressClient $client
    ) {}

    public function findMunicipality(Municipality $municipality): ?array
    {
        $query = ['status' => 'current'];

        if (filled($municipality->nis_code)) {
            $query['nisCode'] = $municipality->nis_code;
        } else {
            $query['name'] = $municipality->name;
        }

        $items = $this->client->get('municipalities', $query)['items'] ?? [];

        return $items[0] ?? null;
    }

    public function fetchPartOfMunicipalities(Municipality $municipality): Collection
    {
        $bestMunicipality = $this->findMunicipality($municipality);

        if (! $bestMunicipality) {
            return collect();
        }

        $nisCode = $bestMunicipality['nisCode'] ?? $municipality->nis_code;
        $page = 1;
        $maxPages = (int) config('services.best_address.max_pages', 25);
        $localities = collect();

        do {
            $payload = $this->client->get('addresses', [
                'nisCode' => $nisCode,
                'status' => 'current',
                'embed' => 'partOfMunicipalities',
                'page' => $page,
            ]);

            foreach (($payload['items'] ?? []) as $item) {
                $locality = $this->extractPartOfMunicipality($item, $payload['embedded'] ?? []);

                if ($locality) {
                    $localities->put($locality['id'] ?? $locality['name'], $locality);
                }
            }

            $totalPages = min((int) ($payload['totalPages'] ?? $page), $maxPages);
            $page++;
        } while ($page <= $totalPages);

        return $localities->values();
    }

    private function extractPartOfMunicipality(array $item, array $embedded): ?array
    {
        $part = $item['hasPartOfMunicipality'] ?? null;

        if (! is_array($part)) {
            return null;
        }

        if (isset($part['href'], $embedded[$part['href']])) {
            $part = $embedded[$part['href']];
        }

        $name = $part['name']['fr']
            ?? $part['name']['nl']
            ?? $part['name']['de']
            ?? null;

        if (blank($name)) {
            return null;
        }

        return [
            'id' => $part['id'] ?? null,
            'name' => $name,
        ];
    }
}
