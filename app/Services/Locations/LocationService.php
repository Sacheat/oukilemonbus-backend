<?php

namespace App\Services\Locations;

use App\Exceptions\GeocodingException;
use App\Models\Location;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Gère la création, la mise à jour et le géocodage des lieux via l'API OpenRouteService.
 *
 * `create()` : si les coordonnées sont déjà fournies (via autocomplétion), réutilise
 *   un emplacement existant avec le même place_id pour éviter les doublons.
 *   Sinon, géocode l'adresse via ORS.
 *
 * `suggest()` : autocomplétion via ORS /pelias/v1/autocomplete (boundary BE, max 6 résultats).
 *   Retourne un tableau vide si ORS n'est pas configuré ou en cas d'erreur HTTP.
 *
 * `geocode()` : résolution ORS /pelias/v1/search → lat/lng/place_id.
 *   Lève GeocodingException si ORS est inaccessible ou ne retourne aucun résultat.
 *
 * `calculateDistance()` : ORS /openrouteservice/v2/directions/driving-car → distance_km + estimated_duration_min.
 *   Lève GeocodingException si l'itinéraire est introuvable.
 *   Le PreviewService possède un fallback haversine × 1.25 si cette méthode échoue.
 *
 * Les URLs sont construites par `endpoint()` : ORS_BASE_URL + préfixe du service
 * (config services.openrouteservice.paths). La clé part toujours dans l'en-tête Authorization.
 */
class LocationService
{
    private ?string $apiKey;

    public function __construct()
    {
        $this->apiKey = config('services.openrouteservice.key');
    }

    /**
     * URL complète d'un appel ORS, ex. endpoint('geocoding', '/search')
     * → https://api.heigit.org/pelias/v1/search.
     *
     * @throws \InvalidArgumentException si le service n'a pas de préfixe dans services.openrouteservice.paths
     */
    public static function endpoint(string $service, string $path): string
    {
        $prefix = config("services.openrouteservice.paths.{$service}");

        if (blank($prefix)) {
            throw new \InvalidArgumentException("Service ORS inconnu : {$service}");
        }

        $baseUrl = rtrim((string) config('services.openrouteservice.base_url'), '/');

        return $baseUrl.$prefix.$path;
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
     * Retourne des suggestions d'adresses via ORS /pelias/v1/autocomplete.
     * Retourne un tableau vide si ORS n'est pas configuré ou en cas d'erreur.
     *
     * @return array<int, array{label: string, formatted_address: string, street: ?string, street_number: ?string, postal_code: ?string, city: ?string, country: string, latitude: float, longitude: float, place_id: ?string}>
     */
    public function suggest(string $query): array
    {
        if (blank($this->apiKey) || blank(config('services.openrouteservice.base_url'))) {
            return [];
        }

        $response = Http::timeout(5)->withHeaders([
            'Authorization' => $this->apiKey,
        ])->get(self::endpoint('geocoding', '/autocomplete'), [
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

        $response = Http::timeout(10)->withHeaders([
            'Authorization' => $this->apiKey,
        ])->get(self::endpoint('geocoding', '/search'), [
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

        try {
            $response = Http::timeout(10)->withHeaders([
                'Authorization' => $this->apiKey,
                'Content-Type' => 'application/json',
            ])->post(self::endpoint('directions', '/driving-car'), [
                'coordinates' => [
                    [$fromLng, $fromLat],
                    [$toLng,   $toLat],
                ],
            ]);
        } catch (ConnectionException $exception) {
            // ORS injoignable ou trop lent : même traitement qu'une réponse en erreur
            // (les appelants basculent sur l'estimation à vol d'oiseau).
            throw new GeocodingException('Le service de calcul d’itinéraire est injoignable.', previous: $exception);
        }

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

    /**
     * Tracé routier entre deux points (ORS, profil voiture, GeoJSON, instructions en français) :
     * géométrie [[lat, lng], …], distance (m), durée (s), étapes [{instruction, name, distance_m, duration_s}].
     *
     * @return array{geometry: list<array{0: float, 1: float}>, distance_m: int, duration_s: int, steps: list<array>}
     *
     * @throws GeocodingException
     */
    public function route(float $fromLat, float $fromLng, float $toLat, float $toLng): array
    {
        $this->ensureConfigured();

        try {
            $response = Http::timeout(15)->withHeaders([
                'Authorization' => $this->apiKey,
                'Content-Type' => 'application/json',
            ])->post(self::endpoint('directions', '/driving-car/geojson'), [
                'coordinates' => [[$fromLng, $fromLat], [$toLng, $toLat]],
                'instructions' => true,
                'language' => 'fr',
                'units' => 'm',
            ]);
        } catch (ConnectionException $exception) {
            throw new GeocodingException('Le service de calcul d’itinéraire est injoignable.', previous: $exception);
        }

        $feature = $response->successful() ? $response->json('features.0') : null;

        if (empty($feature['geometry']['coordinates'])) {
            throw new GeocodingException('Aucun itinéraire trouvé entre les deux points.');
        }

        $summary = $feature['properties']['summary'] ?? [];
        $steps = collect($feature['properties']['segments'] ?? [])
            ->flatMap(fn (array $segment): array => $segment['steps'] ?? [])
            ->map(fn (array $step): array => [
                'instruction' => (string) ($step['instruction'] ?? ''),
                'name' => ($step['name'] ?? '') !== '-' ? (string) ($step['name'] ?? '') : '',
                'distance_m' => (int) round($step['distance'] ?? 0),
                'duration_s' => (int) round($step['duration'] ?? 0),
            ])
            ->values()
            ->all();

        return [
            // GeoJSON : [lng, lat] → [lat, lng] (ordre de Leaflet)
            'geometry' => array_map(fn (array $point): array => [round((float) $point[1], 6), round((float) $point[0], 6)], $feature['geometry']['coordinates']),
            'distance_m' => (int) round($summary['distance'] ?? 0),
            'duration_s' => (int) round($summary['duration'] ?? 0),
            'steps' => $steps,
        ];
    }

    /** @throws GeocodingException */
    private function ensureConfigured(): void
    {
        if (blank($this->apiKey) || blank(config('services.openrouteservice.base_url'))) {
            throw new GeocodingException('La configuration OpenRouteService est incomplete.');
        }
    }
}
