<?php

namespace App\Services\Municipalities;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Client HTTP bas niveau pour l'API GeoNames (JSON).
 *
 * Authentification par paramètre `username` (services.geonames.username).
 * Lance une RuntimeException si le username n'est pas configuré.
 * Timeout de 15 secondes ; propagation des erreurs HTTP via ->throw().
 * L'URL de base est configurable (services.geonames.base_url).
 */
class GeoNamesClient
{
    public function get(string $path, array $query = []): array
    {
        $username = config('services.geonames.username');

        if (blank($username)) {
            throw new RuntimeException('La configuration GeoNames est incomplete.');
        }

        $response = $this->http()
            ->get($this->url($path), [
                ...$query,
                'username' => $username,
            ])
            ->throw();

        return $response->json();
    }

    private function http(): PendingRequest
    {
        return Http::acceptJson()
            ->timeout(15);
    }

    private function url(string $path): string
    {
        return rtrim(config('services.geonames.base_url'), '/').'/'.ltrim($path, '/');
    }
}
