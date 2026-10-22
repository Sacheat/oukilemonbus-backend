<?php

namespace Tests\Feature;

use App\Models\Municipality;
use App\Services\Locations\LocationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Autocomplétion ORS (LocationService::suggest()) : adresses avec numéro uniquement, classées autour de la commune,
 * localité jamais prise dans l'arrondissement (`county`), déduite du code postal quand ORS ne la donne pas.
 * Les features reprennent des réponses réelles d'api.heigit.org pour « Rue Grande 1 ».
 */
class LocationSuggestTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.openrouteservice.key' => 'test-key',
            'services.openrouteservice.base_url' => 'https://api.heigit.org',
        ]);

        Municipality::query()->update(['is_active' => false]);
        Municipality::create([
            'name' => 'Dinant', 'country' => 'BE', 'source' => 'manual', 'is_active' => true,
            'latitude' => 50.25835, 'longitude' => 4.91063,
        ]);
    }

    public function test_suggest_asks_for_addresses_around_the_municipality(): void
    {
        $this->fakeFeatures([]);

        app(LocationService::class)->suggest('Rue Grande 1');

        Http::assertSent(function (Request $request): bool {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query); // parse_str : « . » → « _ »

            return $query['layers'] === 'address'
                && (float) $query['focus_point_lat'] === 50.25835
                && (float) $query['focus_point_lon'] === 4.91063;
        });
    }

    public function test_suggest_builds_the_label_with_the_postal_code_and_the_locality(): void
    {
        $this->fakeFeatures([$this->feature(4.916214, 50.256283, ['postalcode' => '5500', 'locality' => 'Dinant'])]);

        $suggestion = app(LocationService::class)->suggest('Rue Grande 1')[0];

        $this->assertSame('Rue Grande 1, 5500 Dinant', $suggestion['label']);
        $this->assertSame('Rue Grande 1, 5500 Dinant', $suggestion['formatted_address']);
        $this->assertSame('Dinant', $suggestion['city']);
        $this->assertSame('Rue Grande', $suggestion['street']);
        $this->assertSame('1', $suggestion['street_number']);
    }

    public function test_suggest_takes_the_nearest_locality_of_the_postal_code_instead_of_the_district(): void
    {
        // ORS : « Rue Grande 1, NA, Belgium », county Dinant (arrondissement), sans locality → Sommière (Onhaye)
        $this->fakeFeatures([$this->feature(4.85, 50.274, ['postalcode' => '5523'])]);

        $suggestion = app(LocationService::class)->suggest('Rue Grande 1')[0];

        $this->assertSame('Sommière', $suggestion['city']);
        $this->assertSame('Rue Grande 1, 5523 Sommière', $suggestion['label']);
    }

    public function test_suggest_drops_an_address_without_locality_nor_postal_code(): void
    {
        $this->fakeFeatures([
            $this->feature(4.80, 50.10),
            $this->feature(4.916214, 50.256283, ['locality' => 'Dinant']),
        ]);

        $suggestions = app(LocationService::class)->suggest('Rue Grande 1');

        $this->assertCount(1, $suggestions);
        $this->assertSame('Rue Grande 1, Dinant', $suggestions[0]['label']);
    }

    public function test_an_unknown_house_number_falls_back_to_the_street_with_the_typed_number(): void
    {
        // ORS ne renvoie rien quand le numéro est inconnu d'OpenStreetMap ; second appel sur les rues, sans le numéro
        Http::fake(fn (Request $request) => str_contains($request->url(), 'layers=street')
            ? Http::response(['features' => [$this->street('Rue Adolphe Sax', 'polyline:27317893')]])
            : Http::response(['features' => [], 'geocoding' => ['query' => ['parsed_text' => ['street' => 'Rue Adolphe Sax', 'housenumber' => '99']]]]));

        $suggestions = app(LocationService::class)->suggest('Rue Adolphe Sax 99, Dinant');

        $this->assertCount(1, $suggestions);
        $this->assertSame('Rue Adolphe Sax 99, Dinant (position approximative : milieu de la rue)', $suggestions[0]['label']);
        $this->assertSame('Rue Adolphe Sax 99, Dinant', $suggestions[0]['formatted_address']);
        $this->assertSame('99', $suggestions[0]['street_number']);
        $this->assertSame('polyline:27317893#99', $suggestions[0]['place_id']);
        $this->assertTrue($suggestions[0]['approximate']);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'layers=street')
            && $request['text'] === 'Rue Adolphe Sax, Dinant');
    }

    public function test_the_house_number_is_found_when_ors_misreads_the_query(): void
    {
        // « Place Reine Astrid 1 » : ORS lit « Place » comme une localité, pas de housenumber ; le 1 n'est pas connu
        Http::fake(fn (Request $request) => str_contains($request->url(), 'layers=street')
            ? Http::response(['features' => [$this->street('Place Reine Astrid', 'polyline:30676222')]])
            : Http::response(['features' => [
                $this->feature(4.9123, 50.2605, ['name' => 'Place Reine Astrid 16', 'street' => 'Place Reine Astrid', 'housenumber' => '16', 'locality' => 'Dinant']),
            ]]));

        $labels = array_column(app(LocationService::class)->suggest('Place Reine Astrid 1'), 'label');

        $this->assertSame([
            'Place Reine Astrid 1, Dinant (position approximative : milieu de la rue)',
            'Place Reine Astrid 16, Dinant',
        ], $labels);
    }

    public function test_no_street_fallback_when_the_house_number_is_known(): void
    {
        $this->fakeFeatures([$this->feature(4.916214, 50.256283, ['locality' => 'Dinant'])]);

        $suggestions = app(LocationService::class)->suggest('Rue Grande 1');

        $this->assertCount(1, $suggestions);
        $this->assertArrayNotHasKey('approximate', $suggestions[0]);
        Http::assertSentCount(1);
    }

    private function street(string $name, string $id): array
    {
        return [
            'geometry' => ['coordinates' => [4.9119, 50.2613]],
            'properties' => ['id' => $id, 'layer' => 'street', 'name' => $name, 'street' => $name, 'locality' => 'Dinant', 'county' => 'Dinant'],
        ];
    }

    private function fakeFeatures(array $features): void
    {
        Http::fake(['api.heigit.org/pelias/v1/autocomplete*' => Http::response(['features' => $features])]);
    }

    private function feature(float $longitude, float $latitude, array $properties = []): array
    {
        return [
            'geometry' => ['coordinates' => [$longitude, $latitude]],
            'properties' => [
                'id' => 'way/'.crc32($longitude.$latitude),
                'layer' => 'address',
                'name' => 'Rue Grande 1',
                'street' => 'Rue Grande',
                'housenumber' => '1',
                'county' => 'Dinant',
                'label' => 'Rue Grande 1, NA, Belgium',
                ...$properties,
            ],
        ];
    }
}
