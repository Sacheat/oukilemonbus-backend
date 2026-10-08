<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un envoi du planning hebdomadaire : version 1 (envoi initial), puis une version par correction.
 * `signature` : empreinte des trajets du fichier ; une nouvelle version part seulement si elle change.
 * Règles : WeeklyPlanningService.
 */
class WeeklyPlanningDispatch extends Model
{
    protected $fillable = [
        'week_start',
        'version',
        'signature',
        'document_id',
        'trip_count',
        'pending_count',
        'recipient_count',
        'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'week_start' => 'date',
            'version' => 'integer',
            'trip_count' => 'integer',
            'pending_count' => 'integer',
            'recipient_count' => 'integer',
            'sent_at' => 'datetime',
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
