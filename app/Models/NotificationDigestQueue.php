<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id',
    'event_key',
    'category',
    'priority',
    'title',
    'message',
    'reservation_id',
    'document_id',
    'payload',
    'available_at',
    'sent_at',
])]
/**
 * File d'attente des notifications à regrouper en digest email.
 * `available_at` null = envoi immédiat ; non null = fin de la plage de silence (quiet hours).
 * `sent_at` null = en attente de traitement par SendUserDigestJob.
 * Les entrées sont agrégées par utilisateur en un seul email via OperationalDigestNotification.
 */
class NotificationDigestQueue extends Model
{
    use HasFactory;

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'available_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }
}

