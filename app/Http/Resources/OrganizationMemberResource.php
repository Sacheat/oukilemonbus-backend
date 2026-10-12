<?php

namespace App\Http\Resources;

use App\Models\Organization;
use App\Models\User;
use App\Models\UserOrganization;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Membre d'une organisation : identité de l'utilisateur + sa relation avec l'organisation (pivot).
 *
 * RGPD, minimisation : email et téléphone d'un membre ne sont renvoyés (sinon null) qu'aux responsables de
 * l'organisation, aux rôles internes et au membre lui-même. Un simple membre voit les coordonnées des responsables,
 * pour pouvoir les joindre, et le nom des autres.
 */
class OrganizationMemberResource extends JsonResource
{
    private const array FULL_VIEW_ROLES = ['admin', 'agent_communal'];

    public function toArray(Request $request): array
    {
        $relationshipType = $this->pivot?->relationship_type;
        $isManager = in_array($relationshipType, UserOrganization::MANAGER_TYPES, true);
        $showContact = $isManager || $this->viewerSeesAllContacts($request);

        return [
            'user_id' => $this->id,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'full_name' => trim($this->first_name.' '.$this->last_name),
            'email' => $showContact ? $this->email : null,
            'phone' => $showContact ? $this->phone : null,
            'relationship_type' => $relationshipType,
            'is_manager' => $isManager,
            'is_default' => (bool) $this->pivot?->is_default,
            'joined_at' => $this->pivot?->created_at?->toISOString(),
        ];
    }

    /** Le membre lui-même, un rôle interne ou un responsable de l'organisation de la route (calculé une fois par requête). */
    private function viewerSeesAllContacts(Request $request): bool
    {
        $viewer = $request->user();

        if (! $viewer instanceof User) {
            return false;
        }

        if ($viewer->is($this->resource)) {
            return true;
        }

        $cacheKey = 'organization_members.full_view';

        if (! $request->attributes->has($cacheKey)) {
            $organization = $request->route('organization');
            $request->attributes->set($cacheKey, $viewer->hasAnyRole(self::FULL_VIEW_ROLES)
                || ($organization instanceof Organization && $viewer->managesOrganization($organization)));
        }

        return (bool) $request->attributes->get($cacheKey);
    }
}
