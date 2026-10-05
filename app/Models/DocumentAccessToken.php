<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'document_id',
    'user_id',
    'token',
    'expires_at',
    'used_at',
    'revoked_at',
])]
/**
 * Token de téléchargement sécurisé pour un document.
 * Réutilisable jusqu'à `expires_at` (24 h par défaut) ; `used_at` n'est plus écrit.
 * `expires_at` : durée de vie limitée.
 * `revoked_at` : révocation administrative anticipée.
 * Créé par DocumentAccessTokenService, validé lors du GET /api/documents/{document}/signed-download.
 */
class DocumentAccessToken extends Model
{
    use HasFactory;

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }
}

