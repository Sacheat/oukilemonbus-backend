<?php

namespace App\Models;

use App\Enums\CouncilDecisionStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'reservation_id',
    'decision_status',
    'decision_date',
    'recorded_by_user_id',
    'decision_reference',
    'validated_at',
    'notes',
])]
/**
 * Décision du collège municipal sur une réservation en conflit.
 * Une seule décision par réservation (contrainte unique sur reservation_id).
 * APPROUVE lève les conflits de dérogation et permet de faire avancer la réservation.
 * REFUSE déclenche le rejet automatique de la réservation par CouncilDecisionService.
 */
class CouncilDecision extends Model
{
    use HasFactory;

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function recordedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }

    protected function casts(): array
    {
        return [
            'decision_status' => CouncilDecisionStatus::class,
            'decision_date' => 'date',
            'validated_at' => 'datetime',
        ];
    }
}
