<?php

namespace App\Http\Resources;

use App\Models\UserOrganization;
use App\Support\Privacy\PrivacyPolicy;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    private bool $withEffectivePermissions = false;

    /**
     * Ajoute `permissions` : permissions effectives (héritées des rôles), pour que la SPA
     * adapte l'interface sans embarquer la matrice rôles × permissions. Utilisé par /auth/me et /auth/login.
     */
    public function withEffectivePermissions(): self
    {
        $this->withEffectivePermissions = true;

        return $this;
    }

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'full_name' => trim($this->first_name.' '.$this->last_name),
            'email' => $this->email,
            'phone' => $this->phone,
            'active' => $this->active,
            'email_verified_at' => $this->email_verified_at?->toISOString(),
            'anonymized_at' => $this->anonymized_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            // Seulement pour son propre compte (/auth/me, login, inscription) : la SPA affiche la politique si must_accept
            'privacy_policy' => $this->when(
                $request->user()?->is($this->resource) ?? false,
                fn () => app(PrivacyPolicy::class)->statusFor($this->resource)
            ),
            'roles' => $this->whenLoaded('roles', fn () => $this->roles->pluck('name')->values()),
            'permissions' => $this->when(
                $this->withEffectivePermissions,
                fn () => $this->getAllPermissions()->pluck('name')->sort()->values()
            ),
            'organizations' => $this->whenLoaded('organizations', fn () => $this->organizations->map(
                fn ($organization): array => [
                    ...(new OrganizationResource($organization))->resolve($request),
                    'membership' => [
                        'relationship_type' => $organization->pivot?->relationship_type,
                        'is_default' => (bool) $organization->pivot?->is_default,
                        'is_manager' => in_array($organization->pivot?->relationship_type, UserOrganization::MANAGER_TYPES, true),
                    ],
                ]
            )->values()),
            'driver' => $this->whenLoaded('driver', fn () => $this->driver ? new DriverResource($this->driver) : null),
        ];
    }
}
