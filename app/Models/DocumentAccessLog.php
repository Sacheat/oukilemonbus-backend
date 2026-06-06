<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'document_id',
    'document_access_token_id',
    'user_id',
    'ip_address',
    'user_agent',
    'is_success',
    'failure_reason',
])]
/**
 * Journal d'audit de chaque tentative d'accès à un document.
 * `is_success = false` : tentative échouée (token expiré, révoqué ou déjà utilisé).
 * `failure_reason` : détaille la cause de l'échec pour le monitoring.
 * Jamais supprimé — sert de trace forensique.
 */
class DocumentAccessLog extends Model
{
    use HasFactory;

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function token(): BelongsTo
    {
        return $this->belongsTo(DocumentAccessToken::class, 'document_access_token_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected function casts(): array
    {
        return [
            'is_success' => 'boolean',
        ];
    }
}

