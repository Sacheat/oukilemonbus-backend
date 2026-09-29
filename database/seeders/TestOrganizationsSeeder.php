<?php

namespace Database\Seeders;

use App\Enums\OrganizationType;
use App\Models\Location;
use App\Models\Organization;
use App\Models\User;
use App\Models\UserOrganization;
use Illuminate\Database\Seeder;

class TestOrganizationsSeeder extends Seeder
{
    public function run(): void
    {
        $dinantHeadOffice = Location::query()->where('place_id', 'seed-dinant-centre')->firstOrFail();

        $organizations = [
            [
                'name' => 'Ecole communale de Dinant',
                'type' => OrganizationType::ECOLE_COMMUNALE,
                'official_email' => 'direction.ecole@bus-communal.local',
                'phone_contact' => '082200001',
                'name_contact' => 'Direction',
                'location_id' => $dinantHeadOffice->id,
                'is_priority' => true,
                'annual_quota' => 10,
                'reservations_used_this_year' => 0,
                'quota_reset_year' => now(),
            ],
            [
                'name' => 'ASBL Les Amis du Parc',
                'type' => OrganizationType::ASBL,
                'official_email' => 'contact.amisduparc@bus-communal.local',
                'phone_contact' => '082200002',
                'name_contact' => 'Alice Martin',
                'location_id' => $dinantHeadOffice->id,
                'is_priority' => false,
                'annual_quota' => 5,
                'reservations_used_this_year' => 0,
                'quota_reset_year' => now(),
            ],
            [
                'name' => 'Organisation externe de test',
                'type' => OrganizationType::EXTERNE,
                'official_email' => 'contact.externe@bus-communal.local',
                'phone_contact' => '082200003',
                'name_contact' => 'Coordinateur Externe',
                'location_id' => $dinantHeadOffice->id,
                'is_priority' => false,
                'annual_quota' => 3,
                'reservations_used_this_year' => 0,
                'quota_reset_year' => now(),
            ],
        ];

        foreach ($organizations as $attributes) {
            Organization::query()->updateOrCreate(
                ['name' => $attributes['name']],
                $attributes
            );
        }

        $school = Organization::query()->where('name', 'Ecole communale de Dinant')->firstOrFail();
        $asbl = Organization::query()->where('name', 'ASBL Les Amis du Parc')->firstOrFail();
        $user = User::query()->where('email', 'user.test@bus-communal.local')->firstOrFail();
        $responsable = User::query()->where('email', 'responsable.test@bus-communal.local')->firstOrFail();

        $school->users()->syncWithoutDetaching([
            $user->id => [
                'relationship_type' => 'membre',
                'is_default' => ! $this->hasDefaultOrganization($user, $school),
            ],
        ]);

        $asbl->users()->syncWithoutDetaching([
            $responsable->id => [
                'relationship_type' => 'responsable',
                'is_default' => ! $this->hasDefaultOrganization($responsable, $asbl),
            ],
        ]);
    }

    /**
     * Une seule organisation par défaut par utilisateur : on ne marque la nouvelle appartenance
     * que si l'utilisateur n'en a pas déjà une ailleurs (seeder relancé sur une base existante).
     */
    private function hasDefaultOrganization(User $user, Organization $except): bool
    {
        return UserOrganization::query()
            ->where('user_id', $user->id)
            ->where('organization_id', '!=', $except->id)
            ->where('is_default', true)
            ->exists();
    }
}
