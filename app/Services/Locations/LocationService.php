<?php

namespace App\Services\Locations;

use App\Exceptions\GeocodingException;
use App\Models\Location;
use Illuminate\Support\Facades\Http;

class LocationService
{
    private ?string $apiKey;

    private ?string $baseUrl;

    public function __construct()
    {
        $this->apiKey = config('services.openrouteservice.key');
        $this->baseUrl = config('services.openrouteservice.base_url');
    }

    public function create(array $data): Location
    {
        // If coordinates were already provided (e.g. from autocomplete), skip geocoding
        // and reuse an existing location with the same place_id if one exists.
        if (isset($data['latitude'], $data['longitude'])) {
            if (!empty($data['place_id'])) {
                $existing = Location::where('place_id', $data['place_id'])->first();
                if ($existing) {
                    return $existing;
                }
            }

            return Location::create([
                ...$data,
                'provider' => $data['provider'] ?? 'openrouteservice',
                'is_verified' => true,
            ]);
        }

        $geocodedData = $this->geocode($data['formatted_address']);

        return Location::create([
            ...$data,
            ...$geocodedData,
        ]);
    }

    public function update(Location $location, array $data): Location
    {
        if (array_key_exists('formatted_address', $data)) {
            $data = [
                ...$data,
                ...$this->geocode($data['formatted_address']),
            ];
        }

        $location->update($data);

        return $location->refresh();
    }

    /**
     * Retourne des suggestions d'adresses via ORS /geocode/autocomplete.
     * Retourne un tableau vide si ORS n'est pas configuré ou en cas d'erreur.
     *
     * @return array<int, array{label: string, formatted_address: string, street: ?string, street_number: ?string, postal_code: ?string, city: ?string, country: string, latitude: float, longitude: float, place_id: ?string}>
     */
    public function suggest(string $query): array
    {
        if (blank($this->apiKey) || blank($this->baseUrl)) {
            return [];
        }

        $response = Http::timeout(5)->get("{$this->baseUrl}/geocode/autocomplete", [
            'api_key' => $this->apiKey,
            'text' => $query,
            'boundary.country' => 'BE',
            'size' => 6,
            'layers' => 'address,street',
        ]);

        if ($response->failed()) {
            return [];
        }

        return collect($response->json('features', []))
            ->map(fn (array $f): array => [
                'label'             => $f['properties']['label'] ?? null,
                'formatted_address' => $f['properties']['label'] ?? null,
                'street'            => $f['properties']['street'] ?? null,
                'street_number'     => $f['properties']['housenumber'] ?? null,
                'postal_code'       => $f['properties']['postalcode'] ?? null,
                'city'              => $f['properties']['locality'] ?? $f['properties']['county'] ?? null,
                'country'           => 'Belgique',
                'latitude'          => $f['geometry']['coordinates'][1],
                'longitude'         => $f['geometry']['coordinates'][0],
                'place_id'          => $f['properties']['id'] ?? null,
            ])
            ->filter(fn (array $s): bool => !blank($s['label']))
            ->values()
            ->all();
    }

    /**
     * Géocode une adresse et retourne lat, lng, place_id
     *
     * @throws GeocodingException
     */
    public function geocode(string $address): array
    {
        $this->ensureConfigured();

        $response = Http::timeout(10)->get("{$this->baseUrl}/geocode/search", [
            'api_key' => $this->apiKey,
            'text' => $address,
            'boundary.country' => 'BE',
            'size' => 1,
        ]);

        if ($response->failed()) {
            throw new GeocodingException(
                "L'API OpenRouteService est inaccessible pour l'adresse : {$address}"
            );
        }

        $features = $response->json('features');

        if (empty($features)) {
            throw new GeocodingException(
                "Aucun résultat trouvé pour l'adresse : {$address}"
            );
        }

        $feature = $features[0];

        return [
            'latitude' => $feature['geometry']['coordinates'][1],
            'longitude' => $feature['geometry']['coordinates'][0],
            'place_id' => $feature['properties']['id'] ?? null,
            'provider' => 'openrouteservice',
        ];
    }

    /**
     * Calcule la distance routière entre deux points
     *
     * @throws GeocodingException
     */
    public function calculateDistance(
        float $fromLat, float $fromLng,
        float $toLat, float $toLng
    ): array {
        $this->ensureConfigured();

        $response = Http::timeout(10)->withHeaders([
            'Authorization' => $this->apiKey,
            'Content-Type' => 'application/json',
        ])->post("{$this->baseUrl}/v2/directions/driving-car", [
            'coordinates' => [
                [$fromLng, $fromLat],
                [$toLng,   $toLat],
            ],
        ]);

        if ($response->failed()) {
            throw new GeocodingException(
                'Impossible de calculer la distance entre les deux points.'
            );
        }

        $summary = $response->json('routes.0.summary');

        if (empty($summary)) {
            throw new GeocodingException(
                'Aucun itinéraire trouvé entre les deux points.'
            );
        }

        return [
            'distance_km' => round($summary['distance'] / 1000, 2),
            'estimated_duration_min' => round($summary['duration'] / 60),
        ];
    }

    /** @throws GeocodingException */
    private function ensureConfigured(): void
    {
        if (blank($this->apiKey) || blank($this->baseUrl)) {
            throw new GeocodingException('La configuration OpenRouteService est incomplete.');
        }
    }
}
