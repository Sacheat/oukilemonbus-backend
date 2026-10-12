<?php

namespace Tests\Feature;

use App\Models\Municipality;
use App\Models\User;
use App\Services\Documents\Generators\DeliberationGenerator;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TestUsersSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Deux modèles de délibération (demande du client) : subvention en nature (ASBL, externes, CPAS) ou sans (écoles et
 * personnel communaux) ; heures de départ affichées ; composition du Collège réglée par un admin dans Communes.
 */
class DeliberationModelsTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->seed([RolesAndPermissionsSeeder::class, TestUsersSeeder::class]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_the_subsidy_model_quotes_the_circular_and_the_delegation_and_shows_the_hours(): void
    {
        $html = (new DeliberationGenerator)->generateHtml($this->context('subvention', 'ASBL Solidarité et Alternative Dinantaise'));

        $this->assertStringContainsString('circulaire du 30 mai 2013', $html);
        $this->assertStringContainsString('Conseil communal du 9 novembre 2020', $html);
        $this->assertStringContainsString('sous réserve d\'immobilisation du car', $html);
        $this->assertStringContainsString('Départ de Dinant à 10h15 - Départ de Bambois à 15h15', $html);
        $this->assertStringNotContainsString('service enseignement', $html);
        $this->assertStringNotContainsString('Directrice des écoles communales', $html);
    }

    public function test_the_school_model_has_no_subsidy_and_copies_the_schools_director(): void
    {
        $html = (new DeliberationGenerator)->generateHtml($this->context('sans_subvention', 'École maternelle d’Anseremme'));

        $this->assertStringNotContainsString('circulaire du 30 mai 2013', $html);
        $this->assertStringNotContainsString('9 novembre 2020', $html);
        $this->assertStringContainsString('avis positif rendu par le service enseignement', $html);
        $this->assertStringContainsString('Madame LECLERE, Directrice des écoles communales', $html);
        $this->assertStringContainsString('Départ de Dinant à 10h15', $html);
    }

    public function test_only_an_admin_sets_the_college_and_the_document_uses_it(): void
    {
        $municipality = Municipality::query()->where('is_active', true)->orderBy('id')->first()
            ?? Municipality::create(['name' => 'Dinant', 'country' => 'BE', 'source' => 'manual', 'is_active' => true]);

        Sanctum::actingAs(User::query()->where('email', 'agent.test@bus-communal.local')->firstOrFail());
        $this->patchJson("/api/municipalities/{$municipality->id}", ['council_mayor_name' => 'Nouveau BOURGMESTRE'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.council_mayor_name.0', 'Seul un administrateur peut modifier la composition du Collège.');

        Sanctum::actingAs(User::query()->where('email', 'admin.test@bus-communal.local')->firstOrFail());
        $this->patchJson("/api/municipalities/{$municipality->id}", [
            'council_present_text' => "Mme Alice MARTIN, Bourgmestre - Présidente ;\nM. Paul DURAND, Directeur Général ;",
            'council_mayor_title' => 'La Bourgmestre',
            'council_mayor_name' => 'Alice MARTIN',
        ])->assertOk()
            ->assertJsonPath('data.effective_council_mayor_name', 'Alice MARTIN')
            // Champ non réglé : valeur par défaut de config/deliberations.php
            ->assertJsonPath('data.council_director_name', null)
            ->assertJsonPath('data.effective_council_director_name', 'Valentine ROSIER');

        $college = app(\App\Services\Documents\ContextBuilders\DeliberationContextBuilder::class)
            ->build($this->decisionStub())['college'];
        $html = (new DeliberationGenerator)->generateHtml([...$this->context('subvention', 'ASBL'), 'college' => $college]);

        $this->assertStringContainsString('Mme Alice MARTIN, Bourgmestre - Présidente ;', $html);
        $this->assertStringContainsString('La Bourgmestre,<br>', $html);
        $this->assertStringContainsString('Alice MARTIN', $html);
        $this->assertStringNotContainsString('Richard FOURNAUX, Bourgmestre', $html);
    }

    /** Décision sans réservation : seule la partie « college » du contexte est lue ici */
    private function decisionStub(): \App\Models\CouncilDecision
    {
        return new \App\Models\CouncilDecision;
    }

    private function context(string $model, string $organizationName): array
    {
        return [
            'model' => $model,
            'college' => [
                'municipality_name' => 'Dinant',
                'present_lines' => ['M. Richard FOURNAUX, Bourgmestre - Président ;'],
                'director' => ['title' => 'La Directrice Générale', 'name' => 'Valentine ROSIER'],
                'president' => ['title' => 'Le Président', 'name' => 'Richard FOURNAUX'],
                'mayor' => ['title' => 'Le Bourgmestre', 'name' => 'Richard FOURNAUX'],
                'schools_recipient' => 'Madame LECLERE, Directrice des écoles communales',
                'subsidy_circular' => config('deliberations.subsidy_circular'),
                'subsidy_delegation' => config('deliberations.subsidy_delegation'),
            ],
            'decision' => ['date' => '2026-02-04', 'reference' => '32', 'status' => 'approuve'],
            'reservation' => ['id' => 7, 'trip_date' => '2026-07-28', 'departure_time' => '10:15', 'return_time' => '15:15'],
            'organization' => ['name' => $organizationName, 'type' => 'asbl'],
            'locations' => [
                'pickup' => ['city' => 'Dinant', 'formatted_address' => 'Place Reine Astrid, 5500 Dinant'],
                'destination' => ['city' => 'Bambois', 'formatted_address' => 'Lac de Bambois, 5070 Fosses-la-Ville'],
            ],
        ];
    }
}
