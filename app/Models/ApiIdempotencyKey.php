<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id',
    'idempotency_key',
    'method',
    'route_name',
    'request_hash',
    'response_status',
    'response_body',
    'completed_at',
])]
/**
 * Clé d'idempotence API pour les mutations critiques (création, annulation, assignation de réservation).
 * Stocke le code et le corps de la réponse d'origine pour replay à l'identique sur requête dupliquée.
 * `completed_at` null = requête en cours (verrou) ; non null = réponse cachée prête au replay.
 * Voir IdempotencyService pour la logique de vérification et de mise en cache.
 */
class ApiIdempotencyKey extends Model
{
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected function casts(): array
    {
        return [
            'response_body' => 'array',
            'completed_at' => 'datetime',
        ];
    }
}
