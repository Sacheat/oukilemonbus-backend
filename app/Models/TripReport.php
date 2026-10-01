<?php

namespace App\Models;

use App\Enums\TripReportOutcome;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Compte rendu d'un trajet : réalisé (heures et passagers réels) ou non réalisé (motif dans remarks).
 * Un par réservation. Voir TripReportService pour les règles (qui, quand).
 */
class TripReport extends Model
{
    protected $fillable = [
        'reservation_id',
        'driver_id',
        'outcome',
        'actual_departure_time',
        'actual_return_time',
        'actual_nb_children',
        'actual_nb_adults',
        'remarks',
        'reported_by_user_id',
        'reported_at',
    ];

    protected function casts(): array
    {
        return [
            'outcome' => TripReportOutcome::class,
            'reported_at' => 'datetime',
            'actual_nb_children' => 'integer',
            'actual_nb_adults' => 'integer',
        ];
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    public function reportedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by_user_id');
    }
}
