<?php

namespace App\Models;

use App\Enums\DeliveryStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

#[Fillable([
    'correlation_id',
    'notification_id',
    'notification_type',
    'notifiable_type',
    'notifiable_id',
    'reservation_id',
    'document_id',
    'channel',
    'recipient_email',
    'status',
    'provider',
    'provider_message_id',
    'payload',
    'sent_at',
    'failed_at',
    'error_message',
])]
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
            'payload' => 'json',
            'sent_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }
}
