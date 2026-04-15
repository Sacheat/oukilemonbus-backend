<?php

namespace App\Services\Municipalities;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

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
