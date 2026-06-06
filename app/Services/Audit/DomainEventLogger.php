<?php

namespace App\Services\Audit;

use App\Models\DomainEvent;
use App\Models\User;
use Illuminate\Support\Facades\Schema;

/**
 * Enregistre les événements métier dans la table domain_events (audit structuré).
 *
 * Chaque appel à log() crée un enregistrement append-only (UPDATED_AT = null sur le modèle).
 * Les champs old_values / new_values / context sont en JSON pour permettre des comparaisons
 * différentielles sans avoir à recréer l'état depuis l'historique.
 *
 * Si la table domain_events n'existe pas (migration non appliquée), log() est silencieux.
 * Distinct de DomainLogger (fichiers de log) : DomainEventLogger cible la base de données.
 */
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
