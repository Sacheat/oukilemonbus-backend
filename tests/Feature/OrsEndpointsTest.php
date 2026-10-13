<?php

namespace Tests\Feature;

use App\Services\Locations\LocationService;
use App\Services\SystemHealthService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * URLs des appels OpenRouteService sur api.heigit.org : itinéraires sous /openrouteservice/v2/directions,
 * géocodage sous /pelias/v1, clé dans l'en-tête Authorization (jamais dans l'URL).
 */
class OrsEndpointsTest extends TestCase
{
    use DatabaseTransactions;

    private const BASE_URL = 'https://api.heigit.org';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.openrouteservice.key' => 'test-key',
            'services.openrouteservice.base_url' => self::BASE_URL,
        ]);
    }

    public function test_endpoint_joins_base_url_and_service_prefix(): void
    {
        config(['services.openrouteservice.base_url' => self::BASE_URL.'/']);

        $this->assertSame('https://api.heigit.org/pelias/v1/search', LocationService::endpoint('geocoding', '/search'));
        $this->assertSame(
            'https://api.heigit.org/openrouteservice/v2/directions/driving-car',
            LocationService::endpoint('directions', '/driving-car')
        );
    }

    public function test_geocode_calls_pelias_search_with_the_key_in_the_header(): void
    {
        Http::fake(['api.heigit.org/pelias/v1/search*' => Http::response([
            'features' => [['geometry' => ['coordinates' => [4.91, 50.26]], 'properties' => ['id' => 'p1']]],
        ])]);

        app(LocationService::class)->geocode('Rue Grande 1, Dinant');

        Http::assertSent(fn (Request $request): bool => $this->isOrsCall($request, 'GET', self::BASE_URL.'/pelias/v1/search?')
            && $request['text'] === 'Rue Grande 1, Dinant');
    }

    public function test_suggest_calls_pelias_autocomplete_with_the_key_in_the_header(): void
    {
        Http::fake(['api.heigit.org/pelias/v1/autocomplete*' => Http::response(['features' => []])]);

        app(LocationService::class)->suggest('Rue Grande');

        Http::assertSent(fn (Request $request): bool => $this->isOrsCall($request, 'GET', self::BASE_URL.'/pelias/v1/autocomplete?'));
    }

    public function test_distance_calls_openrouteservice_directions(): void
    {
        Http::fake(['api.heigit.org/openrouteservice/v2/directions/*' => Http::response([
            'routes' => [['summary' => ['distance' => 12000, 'duration' => 1800]]],
        ])]);

        app(LocationService::class)->calculateDistance(50.26, 4.91, 50.47, 4.87);

        Http::assertSent(fn (Request $request): bool => $this->isOrsCall(
            $request, 'POST', self::BASE_URL.'/openrouteservice/v2/directions/driving-car'
        ) && $request->url() === self::BASE_URL.'/openrouteservice/v2/directions/driving-car');
    }

    public function test_route_calls_openrouteservice_directions_geojson(): void
    {
        Http::fake(['api.heigit.org/openrouteservice/v2/directions/*' => Http::response([
            'features' => [[
                'geometry' => ['coordinates' => [[4.91, 50.26], [4.87, 50.47]]],
                'properties' => ['summary' => ['distance' => 12000, 'duration' => 1800], 'segments' => []],
            ]],
        ])]);

        app(LocationService::class)->route(50.26, 4.91, 50.47, 4.87);

        Http::assertSent(fn (Request $request): bool => $this->isOrsCall(
            $request, 'POST', self::BASE_URL.'/openrouteservice/v2/directions/driving-car/geojson'
        ));
    }

    public function test_health_checks_call_the_new_urls(): void
    {
        Http::fake([
            'api.heigit.org/pelias/v1/*' => Http::response(['features' => [[]]]),
            'api.heigit.org/openrouteservice/v2/directions/*' => Http::response([
                'routes' => [['summary' => ['distance' => 30000]]],
            ]),
            '*' => Http::response(),
        ]);

        $checks = app(SystemHealthService::class)->check();

        $this->assertSame('ok', $checks['ors_geocoding']['status']);
        $this->assertSame('ok', $checks['ors_routing']['status']);
        Http::assertSent(fn (Request $request): bool => $this->isOrsCall($request, 'GET', self::BASE_URL.'/pelias/v1/search?'));
        Http::assertSent(fn (Request $request): bool => $this->isOrsCall($request, 'POST', self::BASE_URL.'/openrouteservice/v2/directions/driving-car'));
    }

    public function test_health_checks_show_the_called_url_on_error(): void
    {
        Http::fake([
            'api.heigit.org/*' => Http::response('Bad Gateway', 502),
            '*' => Http::response(),
        ]);

        $checks = app(SystemHealthService::class)->check();

        $this->assertSame('error', $checks['ors_geocoding']['status']);
        $this->assertSame('HTTP 502 — https://api.heigit.org/pelias/v1/search', $checks['ors_geocoding']['details']);
        $this->assertSame('error', $checks['ors_routing']['status']);
        $this->assertSame(
            'HTTP 502 — https://api.heigit.org/openrouteservice/v2/directions/driving-car',
            $checks['ors_routing']['details']
        );
    }

    private function isOrsCall(Request $request, string $method, string $urlPrefix): bool
    {
        return $request->method() === $method
            && str_starts_with($request->url(), $urlPrefix)
            && $request->header('Authorization') === ['test-key']
            && ! str_contains($request->url(), 'api_key');
    }
}
