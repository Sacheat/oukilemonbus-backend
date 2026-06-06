<?php

namespace App\Models;

use App\Enums\DeliveryStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

#[Fillable([
    'event_id',
    'event_key',
    'dedupe_key',
    'attempt_id',
    'attempt_number',
    'notification_id',
    'notification_type',
    'notifiable_type',
    'notifiable_id',
    'reservation_id',
    'document_id',
    'channel',
    'priority',
    'recipient_email',
    'status',
    'provider',
    'provider_message_id',
    'payload',
    'sent_at',
    'failed_at',
    'error_message',
])]
/**
 * Trace de livraison d'une notification (email, in-app, etc.).
 * Relation polymorphe `notifiable` — actuellement toujours User.
 * `attempt_number` : incrémenté à chaque retry. `dedupe_key` : empêche les doublons sur replay.
 * `status` : PENDING → SENT ou FAILED selon le résultat de l'envoi.
 */
class NotificationDelivery extends Model
{
    use HasFactory;

    public function notifiable(): MorphTo
    {
        return $this->morphTo();
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
            'status' => DeliveryStatus::class,
            'attempt_number' => 'integer',
            'payload' => 'json',
            'sent_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }
}
