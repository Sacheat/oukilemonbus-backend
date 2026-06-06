<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'aggregate_type',
    'aggregate_id',
    'event_type',
    'actor_user_id',
    'old_values',
    'new_values',
    'context',
])]
/**
 * Journal d'événements métier (audit log).
 * `UPDATED_AT = null` : modèle en ajout seul — les lignes ne sont jamais modifiées.
 * `old_values` / `new_values` : snapshot avant/après pour chaque changement d'état.
 * `aggregate_type` + `aggregate_id` : identifient l'entité concernée (ex: Reservation #42).
 * Alimenté par DomainLogger, jamais par les modèles directement.
 */
class DomainEvent extends Model
{
    public const UPDATED_AT = null;

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
            'context' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
