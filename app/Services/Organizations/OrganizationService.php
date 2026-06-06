<?php

namespace App\Services\Organizations;

use App\Enums\OrganizationType;
use App\Models\Organization;
use Illuminate\Support\Arr;

/**
 * Gère les opérations CRUD et le bannissement des organisations.
 *
 * `prepareData()` déduit automatiquement `is_priority` depuis le type d'organisation
 * si `is_priority` n'est pas fourni explicitement — évite les incohérences entre type et priorité.
 * `ban()` / `unban()` : soft-ban (is_banned = true/false) ; une organisation bannie ne peut plus
 * créer de réservation (vérifié par ReservationPreviewService::ensureOrganizationNotBanned).
 */
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

    public function ban(Organization $organization, string $reason): Organization
    {
        $organization->update([
            'is_banned' => true,
            'banned_reason' => $reason,
        ]);

        return $organization->refresh();
    }

    public function unban(Organization $organization): Organization
    {
        $organization->update([
            'is_banned' => false,
            'banned_reason' => null,
        ]);

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
