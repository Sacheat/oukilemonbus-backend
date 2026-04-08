<?php

namespace App\Models;

use App\Enums\ReservationStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['reservation_id', 'old_status', 'new_status', 'changed_by_user_id', 'reason'])]
class ReservationStatusHistory extends Model
{
    use HasFactory;

    protected $table = 'reservation_status_history';

    const UPDATED_AT = null;

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function changedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by_user_id');
    }

    protected function casts(): array
    {
        return [
            'old_status' => ReservationStatus::class,
            'new_status' => ReservationStatus::class,
            'created_at' => 'datetime',
        ];
    }
}
