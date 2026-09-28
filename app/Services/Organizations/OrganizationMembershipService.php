<?php

namespace App\Services\Organizations;

use App\Models\Organization;
use App\Models\User;
use App\Models\UserOrganization;
use App\Services\Audit\DomainEventLogger;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Gère l'appartenance des utilisateurs aux organisations (pivot user_organization).
 *
 * Garde-fou commun : une organisation garde toujours au moins un responsable
 * (UserOrganization::MANAGER_TYPES). Rétrograder, retirer ou faire partir le dernier
 * responsable est refusé (422) ; un agent peut d'abord en désigner un autre.
 *
 * Quand un membre perd son organisation par défaut, la plus ancienne restante devient
 * la nouvelle organisation par défaut.
 */
class OrganizationMembershipService
{
    public function __construct(
        private readonly DomainEventLogger $eventLogger
    ) {}

    /** @return Collection<int, User> */
    public function members(Organization $organization): Collection
    {
        return $organization->users()
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get();
    }

    public function updateMember(Organization $organization, User $member, string $relationshipType, User $actor): User
    {
        $current = $this->membership($organization, $member);

        if ($this->isManagerType($current->relationship_type) && ! $this->isManagerType($relationshipType)) {
            $this->ensureAnotherManagerRemains($organization, $member);
        }

        $organization->users()->updateExistingPivot($member->getKey(), ['relationship_type' => $relationshipType]);

        $this->eventLogger->log('organization', $organization->getKey(), 'organization.member_updated', $actor,
            ['user_id' => $member->getKey(), 'relationship_type' => $current->relationship_type],
            ['user_id' => $member->getKey(), 'relationship_type' => $relationshipType]
        );

        return $this->memberWithPivot($organization, $member);
    }

    public function removeMember(Organization $organization, User $member, User $actor, string $eventType = 'organization.member_removed'): void
    {
        $current = $this->membership($organization, $member);

        if ($this->isManagerType($current->relationship_type)) {
            $this->ensureAnotherManagerRemains($organization, $member);
        }

        DB::transaction(function () use ($organization, $member, $current): void {
            $organization->users()->detach($member->getKey());

            if ($current->is_default) {
                $this->promoteOldestAsDefault($member);
            }
        });

        $this->eventLogger->log('organization', $organization->getKey(), $eventType, $actor,
            ['user_id' => $member->getKey(), 'relationship_type' => $current->relationship_type],
            null
        );
    }

    public function leave(Organization $organization, User $member): void
    {
        $this->removeMember($organization, $member, $member, 'organization.member_left');
    }

    public function setDefault(Organization $organization, User $member): User
    {
        $this->membership($organization, $member);

        DB::transaction(function () use ($organization, $member): void {
            UserOrganization::query()->where('user_id', $member->getKey())->update(['is_default' => false]);
            $organization->users()->updateExistingPivot($member->getKey(), ['is_default' => true]);
        });

        return $member->load('organizations');
    }

    private function membership(Organization $organization, User $member): UserOrganization
    {
        $membership = UserOrganization::query()
            ->where('organization_id', $organization->getKey())
            ->where('user_id', $member->getKey())
            ->first();

        if ($membership === null) {
            throw ValidationException::withMessages([
                'user' => 'Cet utilisateur n’est pas membre de cette organisation.',
            ]);
        }

        return $membership;
    }

    private function memberWithPivot(Organization $organization, User $member): User
    {
        return $organization->users()->whereKey($member->getKey())->firstOrFail();
    }

    private function ensureAnotherManagerRemains(Organization $organization, User $member): void
    {
        $otherManagers = UserOrganization::query()
            ->where('organization_id', $organization->getKey())
            ->where('user_id', '!=', $member->getKey())
            ->whereIn('relationship_type', UserOrganization::MANAGER_TYPES)
            ->exists();

        if (! $otherManagers) {
            throw ValidationException::withMessages([
                'user' => 'L’organisation doit garder au moins un responsable. Désignez-en un autre avant.',
            ]);
        }
    }

    private function promoteOldestAsDefault(User $member): void
    {
        $nextOrganizationId = UserOrganization::query()
            ->where('user_id', $member->getKey())
            ->orderBy('created_at')
            ->value('organization_id');

        if ($nextOrganizationId !== null) {
            UserOrganization::query()
                ->where('user_id', $member->getKey())
                ->where('organization_id', $nextOrganizationId)
                ->update(['is_default' => true]);
        }
    }

    private function isManagerType(?string $relationshipType): bool
    {
        return in_array($relationshipType, UserOrganization::MANAGER_TYPES, true);
    }
}
