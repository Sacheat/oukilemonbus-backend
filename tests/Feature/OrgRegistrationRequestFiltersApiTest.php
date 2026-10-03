<?php

namespace Tests\Feature;

use App\Enums\OrganizationType;
use App\Enums\RequestStatus;
use App\Models\OrgRegistrationRequest;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * GET /api/org-registration-requests : filtres statuses[], q, organization_type, sort et per_page
 * (page agent « Demandes », file « Nouvelles organisations »).
 */
class OrgRegistrationRequestFiltersApiTest extends TestCase
{
    use DatabaseTransactions;

    private const URL = '/api/org-registration-requests';

    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->seed(RolesAndPermissionsSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->agent = User::factory()->create();
        $this->agent->assignRole('agent_communal');
        Sanctum::actingAs($this->agent);
    }

    public function test_statuses_filter_accepts_one_or_several_statuses(): void
    {
        $pending = $this->makeRequest(['status' => RequestStatus::EN_ATTENTE]);
        $approved = $this->makeRequest(['status' => RequestStatus::APPROUVE]);
        $refused = $this->makeRequest(['status' => RequestStatus::REFUSE]);

        $this->assertSame([$pending], $this->ids('statuses[]=en_attente'));
        $this->assertEqualsCanonicalizing([$approved, $refused], $this->ids('statuses[]=approuve&statuses[]=refuse'));
        $this->assertEqualsCanonicalizing([$pending, $approved, $refused], $this->ids(''));
    }

    public function test_unknown_status_type_or_sort_is_rejected_in_french(): void
    {
        $this->getJson(self::URL.'?statuses[]=bidule')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['statuses.0' => 'Statut inconnu : utilisez en_attente, approuve ou refuse.']);

        $this->getJson(self::URL.'?organization_type=club')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['organization_type' => 'Type d’organisation inconnu.']);

        $this->getJson(self::URL.'?sort=hasard')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['sort' => 'Le tri doit valoir confiance, anciennete ou recentes.']);

        $this->getJson(self::URL.'?per_page=0')->assertUnprocessable()->assertJsonValidationErrors('per_page');
        $this->getJson(self::URL.'?per_page=101')->assertUnprocessable()->assertJsonValidationErrors('per_page');
    }

    public function test_organization_type_filter(): void
    {
        $asbl = $this->makeRequest(['organization_type' => OrganizationType::ASBL]);
        $this->makeRequest(['organization_type' => OrganizationType::CPAS]);

        $this->assertSame([$asbl], $this->ids('organization_type=asbl'));
    }

    public function test_q_searches_name_email_enterprise_number_and_requester(): void
    {
        $paul = User::factory()->create(['first_name' => 'Paul', 'last_name' => 'Séchet', 'email' => 'paul.sechet@example.test']);
        $solidaire = $this->makeRequest([
            'organization_name' => 'ASBL Dinant Solidaire',
            'official_email' => 'contact@solidaire.test',
            'enterprise_number' => '0123.456.789',
            'request_by_user_id' => $paul->id,
        ]);
        $cafe = $this->makeRequest(['organization_name' => 'Café des Sports', 'official_email' => 'cafe@sports.test']);

        $this->assertSame([$solidaire], $this->ids('q=SOLIDAIRE'));                  // nom, sans casse
        $this->assertSame([$cafe], $this->ids('q=cafe'));                            // sans accent
        $this->assertSame([$solidaire], $this->ids('q=contact@'));                   // email officiel
        $this->assertSame([$solidaire], $this->ids('q=0123.456'));                   // numéro d'entreprise
        $this->assertSame([$solidaire], $this->ids('q=sechet'));                     // nom du demandeur
        $this->assertSame([$solidaire], $this->ids('q=paul.sechet@'));               // email du demandeur
        $this->assertSame([$solidaire], $this->ids('q='.urlencode('dinant paul'))); // mots répartis entre demande et demandeur
        $this->assertSame([], $this->ids('q='.urlencode('dinant cafe')));
        $this->assertSame([], $this->ids('q=%25'));                                  // les jokers LIKE sont littéraux
    }

    public function test_filters_combine(): void
    {
        $match = $this->makeRequest(['organization_name' => 'Club Alpha', 'organization_type' => OrganizationType::ASBL, 'status' => RequestStatus::EN_ATTENTE]);
        $this->makeRequest(['organization_name' => 'Club Beta', 'organization_type' => OrganizationType::ASBL, 'status' => RequestStatus::EN_ATTENTE]);
        $this->makeRequest(['organization_name' => 'Club Alpha 2', 'organization_type' => OrganizationType::CPAS, 'status' => RequestStatus::EN_ATTENTE]);
        $this->makeRequest(['organization_name' => 'Club Alpha 3', 'organization_type' => OrganizationType::ASBL, 'status' => RequestStatus::REFUSE]);

        $this->assertSame([$match], $this->ids('statuses[]=en_attente&organization_type=asbl&q=alpha'));
    }

    public function test_sort_by_confidence_then_oldest_then_most_recent(): void
    {
        $old = $this->makeRequest(['confidence_note' => 20, 'created_at' => now()->subDays(3)]);
        $middle = $this->makeRequest(['confidence_note' => 90, 'created_at' => now()->subDays(2)]);
        $recent = $this->makeRequest(['confidence_note' => 20, 'created_at' => now()->subDay()]);

        $this->assertSame([$middle, $recent, $old], $this->ids('', keepOrder: true));
        $this->assertSame([$middle, $recent, $old], $this->ids('sort=confiance', keepOrder: true));
        $this->assertSame([$old, $middle, $recent], $this->ids('sort=anciennete', keepOrder: true));
        $this->assertSame([$recent, $middle, $old], $this->ids('sort=recentes', keepOrder: true));
    }

    public function test_per_page_and_counter_from_meta_total(): void
    {
        foreach (range(1, 3) as $i) {
            $this->makeRequest(['status' => RequestStatus::EN_ATTENTE]);
        }
        $this->makeRequest(['status' => RequestStatus::REFUSE]);

        $this->getJson(self::URL.'?statuses[]=en_attente&per_page=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.per_page', 1);

        $this->getJson(self::URL)->assertOk()->assertJsonPath('meta.per_page', 15)->assertJsonPath('meta.total', 4);
    }

    public function test_pagination_links_keep_the_filters(): void
    {
        foreach (range(1, 3) as $i) {
            $this->makeRequest(['organization_name' => "Club {$i}", 'organization_type' => OrganizationType::ASBL]);
        }

        $response = $this->getJson(self::URL.'?statuses[]=en_attente&organization_type=asbl&q=club&sort=recentes&per_page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $next = urldecode($response->json('links.next'));
        foreach (['statuses[0]=en_attente', 'organization_type=asbl', 'q=club', 'sort=recentes', 'per_page=2'] as $parameter) {
            $this->assertStringContainsString($parameter, $next);
        }
    }

    public function test_a_user_only_sees_their_own_requests_whatever_the_filters(): void
    {
        $member = User::factory()->create();
        $member->assignRole('user');
        $own = $this->makeRequest(['request_by_user_id' => $member->id, 'organization_name' => 'Club à moi']);
        $this->makeRequest(['organization_name' => 'Club à un autre']);

        Sanctum::actingAs($member);

        $this->assertSame([$own], $this->ids(''));
        $this->assertSame([$own], $this->ids('q=club&statuses[]=en_attente'));
        $this->assertSame([], $this->ids('q=autre'));
    }

    public function test_user_without_the_view_permission_is_forbidden_before_validation(): void
    {
        Sanctum::actingAs(User::factory()->create()); // sans rôle : pas de permission org-registration-requests.view

        $this->getJson(self::URL.'?sort=hasard')->assertForbidden();
    }

    /** @param array<string, mixed> $attributes */
    private function makeRequest(array $attributes = []): int
    {
        static $counter = 0;
        $counter++;

        $request = OrgRegistrationRequest::create([
            'request_by_user_id' => User::factory()->create()->id,
            'organization_name' => "Organisation {$counter}",
            'organization_type' => OrganizationType::EXTERNE,
            'official_email' => "demande{$counter}@example.test",
            'status' => RequestStatus::EN_ATTENTE,
            'confidence_note' => 50,
            ...collect($attributes)->except('created_at')->all(),
        ]);

        if (isset($attributes['created_at'])) {
            $request->forceFill(['created_at' => $attributes['created_at']])->save();
        }

        return $request->id;
    }

    /** @return list<int> id renvoyés (triés sauf $keepOrder) */
    private function ids(string $query, bool $keepOrder = false): array
    {
        $ids = array_column($this->getJson(self::URL.'?'.$query)->assertOk()->json('data'), 'id');

        if (! $keepOrder) {
            sort($ids);
        }

        return $ids;
    }
}
