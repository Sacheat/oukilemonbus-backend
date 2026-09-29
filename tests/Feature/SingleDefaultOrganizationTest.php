<?php

namespace Tests\Feature;

use App\Enums\OrganizationType;
use App\Models\Organization;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TestLocationsSeeder;
use Database\Seeders\TestOrganizationsSeeder;
use Database\Seeders\TestUsersSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Au plus une organisation par défaut par utilisateur : migration de données et seeder de test.
 */
class SingleDefaultOrganizationTest extends TestCase
{
    use DatabaseTransactions;

    public function test_data_migration_keeps_oldest_default_or_oldest_membership(): void
    {
        [$a, $b, $c] = [$this->organization('Org A'), $this->organization('Org B'), $this->organization('Org C')];

        // Trois organisations par défaut : on garde la plus ancienne marquée ($b).
        $several = User::factory()->create();
        $this->membership($several, $a, false, '2026-01-01');
        $this->membership($several, $b, true, '2026-02-01');
        $this->membership($several, $c, true, '2026-03-01');

        // Aucune par défaut : la plus ancienne appartenance le devient ($c).
        $none = User::factory()->create();
        $this->membership($none, $c, false, '2026-01-15');
        $this->membership($none, $a, false, '2026-04-01');

        // Déjà correct : inchangé.
        $single = User::factory()->create();
        $this->membership($single, $a, false, '2026-01-01');
        $this->membership($single, $b, true, '2026-05-01');

        $migration = require database_path('migrations/2026_09_29_110000_keep_single_default_organization_per_user.php');
        $migration->up();

        $this->assertSame([$b->id], $this->defaultsOf($several));
        $this->assertSame([$c->id], $this->defaultsOf($none));
        $this->assertSame([$b->id], $this->defaultsOf($single));
    }

    public function test_seeder_does_not_add_a_second_default_organization(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->seed([RolesAndPermissionsSeeder::class, TestUsersSeeder::class, TestLocationsSeeder::class]);

        $user = User::query()->where('email', 'user.test@bus-communal.local')->firstOrFail();
        $existing = $this->organization('Organisation existante');
        $this->membership($user, $existing, true, '2026-01-01');

        $this->seed(TestOrganizationsSeeder::class);
        $this->seed(TestOrganizationsSeeder::class); // relancé : toujours une seule

        $this->assertSame([$existing->id], $this->defaultsOf($user));

        // Sans organisation par défaut préalable, l'appartenance du seeder le devient.
        $responsable = User::query()->where('email', 'responsable.test@bus-communal.local')->firstOrFail();
        $this->assertCount(1, $this->defaultsOf($responsable));
    }

    private function organization(string $name): Organization
    {
        return Organization::create(['name' => $name, 'type' => OrganizationType::ASBL]);
    }

    private function membership(User $user, Organization $organization, bool $isDefault, string $createdAt): void
    {
        DB::table('user_organization')->insert([
            'user_id' => $user->id,
            'organization_id' => $organization->id,
            'relationship_type' => 'membre',
            'is_default' => $isDefault,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }

    /** @return list<int> */
    private function defaultsOf(User $user): array
    {
        return DB::table('user_organization')
            ->where('user_id', $user->id)
            ->where('is_default', true)
            ->pluck('organization_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }
}
