<?php

declare(strict_types=1);

namespace App\Tooling\Inspector\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Entrée du journal Backend Inspector : une requête API mutante et son résultat.
 * Écrite uniquement par RecordInspectorEntry, jamais modifiée ensuite (pas d'updated_at).
 */
final class InspectorEntry extends Model
{
    public const UPDATED_AT = null;

    public const OUTCOME_SUCCESS = 'success';
    public const OUTCOME_ERROR = 'error';

    protected $fillable = [
        'user_id',
        'user_email',
        'method',
        'path',
        'route_name',
        'flow_key',
        'route_params',
        'http_status',
        'outcome',
        'duration_ms',
        'request_payload',
        'response_summary',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'route_params' => 'array',
            'http_status' => 'integer',
            'duration_ms' => 'integer',
            'request_payload' => 'array',
            'response_summary' => 'array',
            'meta' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeOlderThanDays(Builder $query, int $days): Builder
    {
        return $query->where('created_at', '<', now()->subDays($days));
    }
}
