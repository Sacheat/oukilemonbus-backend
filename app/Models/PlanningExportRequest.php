<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'requested_by_user_id',
    'start_date',
    'end_date',
    'statuses',
    'filters',
    'status',
    'document_id',
    'error_message',
    'requested_at',
    'started_at',
    'completed_at',
    'failed_at',
])]
/**
 * Demande d'export du planning (workflow asynchrone).
 * Cycle de statut : pending → processing → succeeded | failed.
 * Le client crée la demande (POST → 202), poll l'état (GET), puis télécharge via `document_id`.
 * Traité par GeneratePlanningExportJob qui met à jour le statut et notifie le demandeur.
 */
class PlanningExportRequest extends Model
{
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    protected function casts(): array
    {
        return [
            'statuses' => 'array',
            'filters' => 'array',
            'start_date' => 'date',
            'end_date' => 'date',
            'requested_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }
}
