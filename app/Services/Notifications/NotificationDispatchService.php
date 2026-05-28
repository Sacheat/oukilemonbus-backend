<?php

namespace App\Services\Notifications;

use App\Enums\DeliveryStatus;
use App\Models\Document;
use App\Models\NotificationDelivery;
use App\Models\Reservation;
use App\Models\User;
use App\Notifications\OperationalNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Throwable;

class NotificationDispatchService
{
    public function __construct(
        private readonly NotificationPreferenceService $preferenceService
    ) {}

    public function dispatch(
        Collection $users,
        string $eventKey,
        string $category,
        string $title,
        string $message,
        ?Reservation $reservation = null,
        ?Document $document = null,
        string $priority = 'normal',
        bool $urgent = false
    ): void {
        $eventId = (string) Str::uuid();
        $reservationId = $reservation?->id;
        $channels = ['database', 'mail'];

        foreach ($users->unique('id') as $user) {
            if (! $user instanceof User || ! $user->active) {
                continue;
            }

            $actionUrl = $document ? $this->documentActionUrl($document) : null;

            foreach ($channels as $channel) {
                if (! $this->preferenceService->channelEnabled($user, $category, $channel)) {
                    continue;
                }

                if (! $urgent && ! $this->passesAntiSpam($user, $eventKey, $channel, $reservationId)) {
                    continue;
                }

                $attemptId = (string) Str::uuid();

                $delivery = NotificationDelivery::create([
                    'event_id' => $eventId,
                    'attempt_id' => $attemptId,
                    'attempt_number' => 1,
                    'notification_id' => null,
                    'notification_type' => OperationalNotification::class,
                    'notifiable_type' => User::class,
                    'notifiable_id' => $user->id,
                    'reservation_id' => $reservationId,
                    'document_id' => $document?->id,
                    'channel' => $channel,
                    'recipient_email' => $channel === 'mail' ? $user->email : null,
                    'status' => DeliveryStatus::PENDING,
                    'provider' => $channel === 'mail' ? 'smtp' : 'database',
                    'provider_message_id' => null,
                    'payload' => [
                        'event_key' => $eventKey,
                        'category' => $category,
                        'priority' => $priority,
                        'title' => $title,
                        'has_document' => $document !== null,
                        'action_url' => $actionUrl,
                    ],
                    'sent_at' => null,
                    'failed_at' => null,
                    'error_message' => null,
                ]);

                try {
                    $notification = new OperationalNotification(
                        eventKey: $eventKey,
                        category: $category,
                        title: $title,
                        message: $message,
                        priority: $priority,
                        reservationId: $reservationId,
                        actionUrl: $actionUrl,
                        document: $document,
                        attachDocumentIfSmall: $channel === 'mail',
                        channels: [$channel]
                    );

                    $user->notify($notification);

                    $delivery->update([
                        'notification_id' => $channel === 'database' ? $notification->id : null,
                        'status' => DeliveryStatus::SENT,
                        'sent_at' => now(),
                    ]);
                } catch (Throwable $exception) {
                    $delivery->update([
                        'status' => DeliveryStatus::FAILED,
                        'failed_at' => now(),
                        'error_message' => $exception->getMessage(),
                    ]);
                }
            }
        }
    }

    private function passesAntiSpam(User $user, string $eventKey, string $channel, ?int $reservationId): bool
    {
        $key = sprintf(
            'notifications:cooldown:%d:%s:%s:%s',
            $user->id,
            $eventKey,
            $channel,
            $reservationId ?? 'none'
        );

        return Cache::add(
            $key,
            true,
            now()->addMinutes((int) config('notifications.anti_spam.cooldown_minutes', 15))
        );
    }

    private function documentActionUrl(Document $document): string
    {
        return URL::temporarySignedRoute(
            'api.documents.signed-download',
            now()->addMinutes((int) config('notifications.mail.signed_link_expiration_minutes', 60 * 24)),
            ['document' => $document->id]
        );
    }
}

