<?php

namespace App\Services\Audit;

use App\Models\DomainEvent;
use App\Models\User;
use Illuminate\Support\Facades\Schema;

class DomainEventLogger
{
    public function log(
        string $aggregateType,
        int $aggregateId,
        string $eventType,
        ?User $actor = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?array $context = null
    ): void {
        if (! Schema::hasTable('domain_events')) {
            return;
        }

        DomainEvent::query()->create([
            'aggregate_type' => $aggregateType,
            'aggregate_id' => $aggregateId,
            'event_type' => $eventType,
            'actor_user_id' => $actor?->id,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'context' => $context,
        ]);
    }
}
