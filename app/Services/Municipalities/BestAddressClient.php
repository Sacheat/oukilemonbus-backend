<?php

namespace App\Services\Municipalities;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class BestAddressClient
{
    public function get(string $path, array $query = []): array
    {
        $response = $this->http()
            ->get($this->url($path), $query)
            ->throw();

        return $response->json();
    }

    private function http(): PendingRequest
    {
        return Http::acceptJson()
            ->withToken($this->token())
            ->withHeaders([
                'BelGov-Trace-Id' => (string) Str::uuid(),
            ])
            ->timeout(15);
    }

    private function token(): string
    {
        return Cache::remember('best_address.oauth_token', now()->addMinutes(55), function (): string {
            $clientId = config('services.best_address.client_id');
            $clientSecret = config('services.best_address.client_secret');

            if (blank($clientId) || blank($clientSecret)) {
                throw new RuntimeException('La configuration OAuth BeSt Address est incomplete.');
            }

            $response = Http::asForm()
                ->post(config('services.best_address.token_url'), [
                    'grant_type' => 'client_credentials',
                    'client_id' => $clientId,
                    'client_secret' => $clientSecret,
                ])
                ->throw();

            return $response->json('access_token');
        });
    }

    private function url(string $path): string
    {
        return rtrim(config('services.best_address.base_url'), '/').'/'.ltrim($path, '/');
    }
}
