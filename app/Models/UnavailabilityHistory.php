<?php

namespace App\Models;

use App\Casts\LocalDateTime;
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
/**
 * Historique immuable des modifications d'une indisponibilité.
 * `UPDATED_AT = null` : ajout seul. Note : table = `unavailabilities_history` (pluriel avec s).
 * `change_reason` : motif saisi par l'agent lors de la modification.
 */
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
            'old_start_datetime' => LocalDateTime::class,
            'new_start_datetime' => LocalDateTime::class,
            'old_end_datetime' => LocalDateTime::class,
            'new_end_datetime' => LocalDateTime::class,
            'created_at' => 'datetime',
        ];
    }
}
