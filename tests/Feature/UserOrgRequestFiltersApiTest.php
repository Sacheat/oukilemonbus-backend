<?php

namespace Tests\Feature;

use App\Enums\OrganizationType;
use App\Enums\RequestStatus;
use App\Models\Organization;
use App\Models\User;
use App\Models\UserOrgRequest;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * GET /api/user-org-requests : filtres statuses[], q, organization_id, sort et per_page (page agent « Demandes »).
 */
class UserOrgRequestFiltersApiTest extends TestCase
{
    use DatabaseTransactions;

    private const URL = '/api/user-org-requests';

    private Organization $parc;

    private Organization $ecole;

    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->seed(RolesAndPermissionsSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->parc = Organization::create(['name' => 'Café du Parc', 'type' => OrganizationType::ASBL, 'official_email' => 'parc@example.test']);
        $this->ecole = Organization::create(['name' => 'École des Fagnes', 'type' => OrganizationType::ECOLE_COMMUNALE, 'official_email' => 'fagnes@example.test']);

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

    public function test_unknown_status_or_sort_is_rejected_in_french(): void
    {
        $this->getJson(self::URL.'?statuses[]=bidule')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['statuses.0' => 'Statut inconnu : utilisez en_attente, approuve ou refuse.']);

        $this->getJson(self::URL.'?statuses=en_attente')->assertUnprocessable()->assertJsonValidationErrors('statuses');

        $this->getJson(self::URL.'?sort=hasard')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['sort' => 'Le tri doit valoir confiance, anciennete ou recentes.']);

        $this->getJson(self::URL.'?per_page=0')->assertUnprocessable()->assertJsonValidationErrors('per_page');
        $this->getJson(self::URL.'?per_page=101')->assertUnprocessable()->assertJsonValidationErrors('per_page');
        $this->getJson(self::URL.'?organization_id=abc')->assertUnprocessable()->assertJsonValidationErrors('organization_id');
    }

    public function test_q_searches_organization_and_requester_ignoring_case_and_accents(): void
    {
        $paul = User::factory()->create(['first_name' => 'Paul', 'last_name' => 'Séchet', 'email' => 'paul.sechet@example.test']);
        $marie = User::factory()->create(['first_name' => 'Marie', 'last_name' => 'Dupont', 'email' => 'marie@example.test']);
        $cafePaul = $this->makeRequest(['organization_id' => $this->parc->id, 'requested_by_user_id' => $paul->id]);
        $ecoleMarie = $this->makeRequest(['organization_id' => $this->ecole->id, 'requested_by_user_id' => $marie->id]);

        $this->assertSame([$cafePaul], $this->ids('q=CAFE'));            // nom de l'organisation, sans accent ni casse
        $this->assertSame([$ecoleMarie], $this->ids('q=ecole'));
        $this->assertSame([$cafePaul], $this->ids('q=sechet'));          // nom du demandeur
        $this->assertSame([$ecoleMarie], $this->ids('q=marie'));         // prénom
        $this->assertSame([$cafePaul], $this->ids('q=paul.sechet@'));    // email
        $this->assertSame([$cafePaul], $this->ids('q='.urlencode('café paul')));   // mots répartis entre organisation et demandeur
        $this->assertSame([], $this->ids('q='.urlencode('café marie')));
        $this->assertSame([], $this->ids('q=%25'));                      // les jokers LIKE sont littéraux
    }

    public function test_organization_id_filter(): void
    {
        $atParc = $this->makeRequest(['organization_id' => $this->parc->id]);
        $this->makeRequest(['organization_id' => $this->ecole->id]);

        $this->assertSame([$atParc], $this->ids("organization_id={$this->parc->id}"));
    }

    public function test_filters_combine(): void
    {
        $paul = User::factory()->create(['first_name' => 'Paul', 'last_name' => 'Martin']);
        $match = $this->makeRequest(['organization_id' => $this->parc->id, 'requested_by_user_id' => $paul->id, 'status' => RequestStatus::EN_ATTENTE]);
        $this->makeRequest(['organization_id' => $this->parc->id, 'requested_by_user_id' => $paul->id, 'status' => RequestStatus::REFUSE]);
        $this->makeRequest(['organization_id' => $this->ecole->id, 'requested_by_user_id' => $paul->id, 'status' => RequestStatus::EN_ATTENTE]);
        $this->makeRequest(['organization_id' => $this->parc->id, 'status' => RequestStatus::EN_ATTENTE]);

        $this->assertSame(
            [$match],
            $this->ids("statuses[]=en_attente&organization_id={$this->parc->id}&q=paul")
        );
    }

    public function test_sort_by_confidence_then_oldest_then_most_recent(): void
    {
        $old = $this->makeRequest(['confidence_note' => 20, 'created_at' => now()->subDays(3)]);
        $middle = $this->makeRequest(['confidence_note' => 90, 'created_at' => now()->subDays(2)]);
        $recent = $this->makeRequest(['confidence_note' => 20, 'created_at' => now()->subDay()]);

        // Défaut : confiance décroissante, puis plus récentes d'abord à note égale.
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
        $this->makeRequest(['status' => RequestStatus::APPROUVE]);

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
            $this->makeRequest(['status' => RequestStatus::EN_ATTENTE, 'organization_id' => $this->parc->id]);
        }

        $response = $this->getJson(self::URL."?statuses[]=en_attente&organization_id={$this->parc->id}&q=cafe&sort=anciennete&per_page=2")
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $next = urldecode($response->json('links.next'));
        foreach (['statuses[0]=en_attente', "organization_id={$this->parc->id}", 'q=cafe', 'sort=anciennete', 'per_page=2'] as $parameter) {
            $this->assertStringContainsString($parameter, $next);
        }
    }

    public function test_visibility_scope_is_unchanged_by_the_filters(): void
    {
        $member = User::factory()->create();
        $member->assignRole('user');
        $manager = User::factory()->create();
        $manager->assignRole('user');
        $this->ecole->users()->attach($manager->id, ['relationship_type' => 'responsable', 'is_default' => true]);

        $own = $this->makeRequest(['requested_by_user_id' => $member->id, 'organization_id' => $this->parc->id]);
        $forEcole = $this->makeRequest(['organization_id' => $this->ecole->id]);
        $foreign = $this->makeRequest(['organization_id' => $this->parc->id]);

        Sanctum::actingAs($member);
        $this->assertSame([$own], $this->ids('statuses[]=en_attente'));
        // Un filtre ne fait pas apparaître la demande d'un autre.
        $this->assertSame([$own], $this->ids("organization_id={$this->parc->id}"));
        $this->assertSame([], $this->ids("organization_id={$this->ecole->id}"));

        Sanctum::actingAs($manager);
        $this->assertSame([$forEcole], $this->ids(''));
        $this->assertSame([], $this->ids("organization_id={$this->parc->id}"));
        $this->assertNotContains($foreign, $this->ids(''));
    }

    public function test_user_without_the_view_permission_is_forbidden_before_validation(): void
    {
        Sanctum::actingAs(User::factory()->create()); // sans rôle : pas de permission user-org-requests.view

        $this->getJson(self::URL.'?sort=hasard')->assertForbidden();
    }

    /** @param array<string, mixed> $attributes */
    private function makeRequest(array $attributes = []): int
    {
        $request = UserOrgRequest::create([
            'organization_id' => $this->parc->id,
            'requested_by_user_id' => User::factory()->create()->id,
            'requested_role' => 'prof',
            'status' => RequestStatus::EN_ATTENTE,
            'confidence_note' => 50,
            ...collect($attributes)->except('created_at')->all(),
        ]);

        if (isset($attributes['created_at'])) {
            $request->forceFill(['created_at' => $attributes['created_at']])->save();
        }

        return $request->request_id;
    }

    /** @return list<int> request_id renvoyés (triés sauf $keepOrder) */
    private function ids(string $query, bool $keepOrder = false): array
    {
        $ids = array_column($this->getJson(self::URL.'?'.$query)->assertOk()->json('data'), 'request_id');

        if (! $keepOrder) {
            sort($ids);
        }

        return $ids;
    }
}
