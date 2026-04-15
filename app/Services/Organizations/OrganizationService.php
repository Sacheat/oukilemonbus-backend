<?php

namespace App\Services\Organizations;

use App\Enums\OrganizationType;
use App\Models\Organization;
use Illuminate\Support\Arr;

class OrganizationService
{
    public function create(array $data): Organization
    {
        $data = $this->prepareData($data);

        return Organization::create(Arr::only($data, [
            'name',
            'type',
            'official_email',
            'phone_contact',
            'name_contact',
            'location_id',
            'is_priority',
            'annual_quota',
            'document_proof_path',
        ]))->refresh();
    }

    public function update(Organization $organization, array $data): Organization
    {
        $data = $this->prepareData($data);

        $organization->update(Arr::only($data, [
            'name',
            'type',
            'official_email',
            'phone_contact',
            'name_contact',
            'location_id',
            'is_priority',
            'annual_quota',
            'document_proof_path',
        ]));

        return $organization->refresh();
    }

    private function prepareData(array $data): array
    {
        if (array_key_exists('type', $data) && ! array_key_exists('is_priority', $data)) {
            $type = $data['type'] instanceof OrganizationType
                ? $data['type']
                : OrganizationType::from($data['type']);

            $data['is_priority'] = $type->isPriorityByDefault();
        }

        return $data;
    }
}
