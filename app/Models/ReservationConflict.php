<?php

namespace App\Models;

use App\Enums\ReservationConflictType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'reservation_id',
    'type',
    'message',
    'related_reservation_id',
    'is_resolved',
    'resolved_by_user_id',
    'resolved_at',
])]
class ReservationConflict extends Model
{
    use HasFactory;

    const UPDATED_AT = null;

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function relatedReservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class, 'related_reservation_id');
    }

    public function resolvedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_user_id');
    }

    protected function casts(): array
    {
        return [
            'type' => ReservationConflictType::class,
            'is_resolved' => 'boolean',
            'resolved_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }
}
