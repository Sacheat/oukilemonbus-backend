<?php

namespace Database\Seeders;

use App\Enums\OrganizationType;
use App\Enums\RequestStatus;
use App\Models\Location;
use App\Models\OrgRegistrationRequest;
use App\Models\Organization;
use App\Models\User;
use App\Models\UserOrgRequest;
use Illuminate\Database\Seeder;

class TestRequestsSeeder extends Seeder
{
    public function run(): void
    {
        $requester = User::query()->where('email', 'user.test@bus-communal.local')->firstOrFail();
        $reviewer = User::query()->where('email', 'reviewer.test@bus-communal.local')->firstOrFail();
        $organization = Organization::query()->where('name', 'ASBL Les Amis du Parc')->firstOrFail();
        $location = Location::query()->where('place_id', 'seed-dinant-centre')->firstOrFail();

        OrgRegistrationRequest::query()->updateOrCreate(
            ['organization_name' => 'ASBL Dinant Solidaire'],
            [
                'request_by_user_id' => $requester->id,
                'organization_type' => OrganizationType::ASBL,
                'enterprise_number' => '0123.456.789',
                'official_email' => 'contact@dinant-solidaire.test',
                'phone' => '082000000',
                'location_id' => $location->id,
                'contact_person_name' => 'Alice Martin',
                'contact_person_role' => 'Responsable',
                'proof_document_path' => 'proofs/asbl-dinant-solidaire.pdf',
                'comment' => 'Demande de creation',
                'status' => RequestStatus::EN_ATTENTE,
                'confidence_note' => 96,
            ]
        );

        UserOrgRequest::query()->updateOrCreate(
            [
                'organization_id' => $organization->id,
                'requested_by_user_id' => $requester->id,
                'requested_role' => 'responsable',
            ],
            [
                'justification' => 'Je coordonne les activites et les inscriptions.',
                'proof_document_path' => 'proofs/rattachement-asbl.pdf',
                'status' => RequestStatus::EN_ATTENTE,
                'confidence_note' => 100,
            ]
        );

        UserOrgRequest::query()->updateOrCreate(
            [
                'organization_id' => $organization->id,
                'requested_by_user_id' => $reviewer->id,
                'requested_role' => 'animateur',
            ],
            [
                'justification' => 'Participation hebdomadaire aux activites.',
                'status' => RequestStatus::REFUSE,
                'confidence_note' => 20,
                'reviewed_by_user_id' => $reviewer->id,
                'reviewed_at' => now(),
                'rejection_reason' => 'Justificatif insuffisant.',
            ]
        );
    }
}
