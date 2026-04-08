<?php

namespace App\Models;

use App\Enums\UnavailabilityReasonType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'unavailability_id',
    'old_reason_type',
    'new_reason_type',
    'old_start_datetime',
    'new_start_datetime',
    'old_end_datetime',
    'new_end_datetime',
    'old_reason',
    'new_reason',
    'changed_by_user_id',
    'change_reason',
])]
class UnavailabilityHistory extends Model
{
    use HasFactory;

    protected $table = 'unavailabilities_history';

    const UPDATED_AT = null;

    public function unavailability(): BelongsTo
    {
        return $this->belongsTo(Unavailability::class);
    }

    public function changedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by_user_id');
    }

    protected function casts(): array
    {
        return [
            'old_reason_type' => UnavailabilityReasonType::class,
            'new_reason_type' => UnavailabilityReasonType::class,
            'old_start_datetime' => 'datetime',
            'new_start_datetime' => 'datetime',
            'old_end_datetime' => 'datetime',
            'new_end_datetime' => 'datetime',
            'created_at' => 'datetime',
        ];
    }
}
