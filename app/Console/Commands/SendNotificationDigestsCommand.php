<?php

namespace App\Console\Commands;

use App\Models\NotificationDigestQueue;
use App\Models\NotificationDelivery;
use App\Models\User;
use App\Notifications\OperationalDigestNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Throwable;

class SendNotificationDigestsCommand extends Command
{
    protected $signature = 'notifications:send-digests';

    protected $description = 'Envoie les notifications digest en attente';

    public function handle(): int
    {
        $pending = NotificationDigestQueue::query()
            ->whereNull('sent_at')
            ->where(function ($query): void {
                $query->whereNull('available_at')
                    ->orWhere('available_at', '<=', now());
            })
            ->with('user')
            ->orderBy('id')
            ->get()
            ->groupBy('user_id');

        foreach ($pending as $userId => $items) {
            $user = $items->first()?->user;
            if (! $user instanceof User || ! $user->active) {
                continue;
            }

            $eventId = (string) Str::uuid();
            $attemptId = (string) Str::uuid();
            $itemsPayload = $items->map(fn ($item) => [
                'title' => $item->title,
                'message' => $item->message,
                'category' => $item->category,
                'priority' => $item->priority,
            ])->values()->all();

            $delivery = NotificationDelivery::create([
                'event_id' => $eventId,
                'event_key' => 'notifications.digest',
                'dedupe_key' => hash('sha256', 'digest|'.$userId.'|'.now()->format('YmdHi')),
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
                $user->notify(new OperationalDigestNotification(
                    subject: 'Resume de vos notifications',
                    items: $itemsPayload
                ));

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
            }
        }

        return self::SUCCESS;
    }
}
