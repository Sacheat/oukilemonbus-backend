<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

#[Fillable(['user_id', 'organization_id', 'relationship_type', 'is_default'])]
/**
 * Pivot enrichi pour la relation User ↔ Organization.
 * `relationship_type` : rôle de l'utilisateur dans l'organisation (prof, directeur, etc.).
 *   Les types de MANAGER_TYPES font de l'utilisateur un responsable de CETTE organisation
 *   (gestion des réservations et des membres) ; les autres sont de simples membres.
 * `is_default` : organisation principale de l'utilisateur — utilisée comme contexte par défaut.
 * `$incrementing = false` : clé composite (user_id, organization_id), pas d'auto-increment.
 */
class UserOrganization extends Pivot
{
    /** Types de relation qui donnent les droits de responsable sur l'organisation. */
    public const array MANAGER_TYPES = ['responsable', 'directeur'];

    /** Types de relation acceptés (demandes d'adhésion, gestion des membres). */
    public const array RELATIONSHIP_TYPES = ['responsable', 'directeur', 'prof', 'animateur', 'referent', 'membre'];

    protected $table = 'user_organization';

    public $incrementing = false;

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
        ];
    }
}
