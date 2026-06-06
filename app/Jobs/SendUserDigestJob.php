<?php

namespace App\Jobs;

use App\Models\NotificationDigestQueue;
use App\Models\NotificationDelivery;
use App\Models\User;
use App\Notifications\OperationalDigestNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use Throwable;

/**
 * Envoie le digest de notifications par email pour un utilisateur donné.
 * Traite uniquement les entrées NotificationDigestQueue non envoyées et dont `available_at`
 * est passé (null = immédiat, sinon heure de fin de plage de silence).
 * Crée un NotificationDelivery pour traçabilité avant d'envoyer, puis le met à jour selon le résultat.
 * Un job par utilisateur : l'isolement évite qu'une erreur pour un utilisateur bloque les autres.
 * Les items sont agrégés en un seul email via OperationalDigestNotification.
 */
class SendUserDigestJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public bool $failOnTimeout = true;

    public function __construct(
        public readonly int $userId
    ) {
        $this->onQueue((string) config('queue.names.low', 'low'));
    }

    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(): void
    {
        // available_at null = immédiat ; sinon on attend la fin de la plage de silence.
        $items = NotificationDigestQueue::query()
            ->where('user_id', $this->userId)
            ->whereNull('sent_at')
            ->where(function ($query): void {
                $query->whereNull('available_at')
                    ->orWhere('available_at', '<=', now());
            })
            ->orderBy('id')
            ->get();

        if ($items->isEmpty()) {
            return;
        }

        $user = User::query()->find($this->userId);
        if (! $user instanceof User || ! $user->active) {
            return;
        }

        $eventId = (string) Str::uuid();
        $attemptId = (string) Str::uuid();
        $itemsPayload = $items->map(fn ($item) => [
            'title' => $item->title,
            'message' => $item->message,
            'category' => $item->category,
            'priority' => $item->priority,
        ])->values()->all();

        // Delivery créé avant l'envoi pour traçabilité même en cas d'échec.
        $delivery = NotificationDelivery::create([
            'event_id' => $eventId,
            'event_key' => 'notifications.digest',
            // Clé de déduplication à la minute pour éviter les doubles envois en cas de retry rapide.
            'dedupe_key' => hash('sha256', 'digest|'.$this->userId.'|'.now()->format('YmdHi')),
            'attempt_id' => $attemptId,
            'attempt_number' => 1,
            'notification_id' => null,
            'notification_type' => OperationalDigestNotification::class,
            'notifiable_type' => User::class,
            'notifiable_id' => $user->id,
            'reservation_id' => null,
            'document_id' => null,
            'channel' => 'mail',
            'priority' => 'normal',
            'recipient_email' => $user->email,
            'status' => 'pending',
            'provider' => (string) config('mail.default', 'smtp'),
            'provider_message_id' => null,
            'payload' => [
                'count' => count($itemsPayload),
                'items' => $itemsPayload,
            ],
            'sent_at' => null,
            'failed_at' => null,
            'error_message' => null,
        ]);

        try {
            $notification = new OperationalDigestNotification(
                subject: 'Resume de vos notifications',
                items: $itemsPayload
            );
            $notification->onQueue((string) config('queue.names.low', 'low'));

            $user->notify($notification);

            $delivery->update([
                'status' => 'sent',
                'sent_at' => now(),
            ]);

            NotificationDigestQueue::query()
                ->whereIn('id', $items->pluck('id'))
                ->update(['sent_at' => now()]);
        } catch (Throwable $exception) {
            $delivery->update([
                'status' => 'failed',
                'failed_at' => now(),
                'error_message' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }
}

