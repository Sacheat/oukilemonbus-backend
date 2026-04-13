<?php

namespace App\Services;

use App\Exceptions\GeocodingException;
use Illuminate\Support\Facades\Http;

class LocationService
{
    private string $apiKey;
    private string $baseUrl;

    public function __construct()
    {
        $this->apiKey  = config('services.openrouteservice.key');
        $this->baseUrl = config('services.openrouteservice.base_url');
    }

    /**
     * Géocode une adresse et retourne lat, lng, place_id
     *
     * @throws GeocodingException
     */
    public function geocode(string $address): array
    {
        $response = Http::get("{$this->baseUrl}/geocode/search", [
            'api_key'          => $this->apiKey,
            'text'             => $address,
            'boundary.country' => 'BE',
            'size'             => 1,
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
            'latitude'  => $feature['geometry']['coordinates'][1],
            'longitude' => $feature['geometry']['coordinates'][0],
            'place_id'  => $feature['properties']['id'] ?? null,
            'provider'  => 'openrouteservice',
        ];
    }

    /**
     * Calcule la distance routière entre deux points
     *
     * @throws GeocodingException
     */
    public function calculateDistance(
        float $fromLat, float $fromLng,
        float $toLat,   float $toLng
    ): array
    {
        $response = Http::withHeaders([
            'Authorization' => $this->apiKey,
            'Content-Type'  => 'application/json',
        ])->post("{$this->baseUrl}/v2/directions/driving-car", [
            'coordinates' => [
                [$fromLng, $fromLat],
                [$toLng,   $toLat],
            ],
        ]);

        if ($response->failed()) {
            throw new GeocodingException(
                "Impossible de calculer la distance entre les deux points."
            );
        }

        $summary = $response->json('routes.0.summary');

        if (empty($summary)) {
            throw new GeocodingException(
                "Aucun itinéraire trouvé entre les deux points."
            );
        }

        return [
            'distance_km'          => round($summary['distance'] / 1000, 2),
            'estimated_duration_min' => round($summary['duration'] / 60),
        ];
    }
}
