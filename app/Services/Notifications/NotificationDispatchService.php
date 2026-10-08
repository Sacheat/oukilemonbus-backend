<?php

namespace App\Services\Notifications;

use App\Enums\DeliveryStatus;
use App\Models\Document;
use App\Models\NotificationDigestQueue;
use App\Models\NotificationDelivery;
use App\Models\Reservation;
use App\Models\User;
use App\Notifications\OperationalNotification;
use App\Services\Documents\DocumentAccessTokenService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Throwable;

/**
 * Point d'entrée unique pour l'envoi de notifications opérationnelles (in-app et email).
 *
 * Pipeline pour chaque utilisateur × canal :
 *   1. Vérification des préférences (channelEnabled).
 *   2. Pour les emails non urgents : mise en file digest si frequency ≠ 'none' ou en quiet hours.
 *   3. Anti-spam : cooldown configurable par (user, eventKey, canal, reservation) via Cache.
 *   4. Déduplication : sha256 sur (eventKey, canal, userId, reservationId, documentId, message) ;
 *      un enregistrement PENDING ou SENT bloque l'envoi d'un doublon.
 *   5. Création du NotificationDelivery (traçabilité), puis envoi via Laravel Notifications.
 *      La base de données (in-app) est envoyée synchrone ; l'email est mis en queue.
 *
 * `documentActionUrl()` émet un DocumentAccessToken réutilisable jusqu'à expiration (signedRoute + token).
 * La priorité high utilise la queue 'critical' ; les emails normaux la queue 'default'.
 *
 * Deux paramètres facultatifs servent à l'email seulement (le canal `database` ne change pas) :
 *   - `$reason` : motif d'un refus, d'une annulation ou d'un trajet non réalisé, affiché en citation ; `$message` garde
 *     le motif (« Motif : … ») pour la cloche du front, l'email le retire du texte et l'affiche à part ;
 *   - `$link` : ['path' => '/gestion/demandes?…', 'label' => 'Ouvrir la demande'], lien vers la SPA quand il n'y a pas
 *     de réservation (demandes d'adhésion et de création). Avec une réservation, le lien dépend du destinataire.
 *
 * `$antiSpamScope` distingue, sans réservation, deux envois du même événement qui ne sont pas des doublons (ex. les
 * versions corrigées de deux semaines de planning envoyées au même passage) ; par défaut, la réservation.
 */
class NotificationDispatchService
{
    public function __construct(
        private readonly NotificationPreferenceService $preferenceService,
        private readonly DocumentAccessTokenService $documentAccessTokenService
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
        bool $urgent = false,
        ?string $reason = null,
        ?array $link = null,
        ?string $antiSpamScope = null
    ): void {
        $eventId = (string) Str::uuid();
        $reservationId = $reservation?->id;
        $channels = ['database', 'mail'];

        foreach ($users->unique('id') as $user) {
            if (! $user instanceof User || ! $user->active) {
                continue;
            }

            $actionUrl = $document ? $this->documentActionUrl($document, $user) : null;

            foreach ($channels as $channel) {
                if (! $this->preferenceService->channelEnabled($user, $category, $channel)) {
                    continue;
                }

                if ($channel === 'mail' && ! $urgent && $this->shouldQueueDigest($user)) {
                    $this->queueDigestItem($user, $eventKey, $category, $title, $message, $priority, $reservation, $document, $link);
                    continue;
                }

                if (! $urgent && ! $this->passesAntiSpam($user, $eventKey, $channel, $antiSpamScope ?? $reservationId)) {
                    continue;
                }

                $attemptId = (string) Str::uuid();
                $dedupeKey = $this->dedupeKey(
                    $eventKey,
                    $channel,
                    $user->id,
                    $reservationId,
                    $document?->id,
                    $message
                );

                $delivery = NotificationDelivery::query()
                    ->where('dedupe_key', $dedupeKey)
                    ->first();

                if (
                    $delivery !== null
                    && in_array(
                        (string) (($delivery->status instanceof DeliveryStatus) ? $delivery->status->value : $delivery->status),
                        [DeliveryStatus::PENDING->value, DeliveryStatus::SENT->value],
                        true
                    )
                ) {
                    continue;
                }

                if ($delivery === null) {
                    $delivery = new NotificationDelivery();
                    $delivery->attempt_number = 1;
                } else {
                    $delivery->attempt_number = (int) $delivery->attempt_number + 1;
                }

                $delivery->fill([
                    'event_id' => $eventId,
                    'event_key' => $eventKey,
                    'dedupe_key' => $dedupeKey,
                    'attempt_id' => $attemptId,
                    'notification_id' => null,
                    'notification_type' => OperationalNotification::class,
                    'notifiable_type' => User::class,
                    'notifiable_id' => $user->id,
                    'reservation_id' => $reservationId,
                    'document_id' => $document?->id,
                    'channel' => $channel,
                    'priority' => $priority,
                    'recipient_email' => $channel === 'mail' ? $user->email : null,
                    'status' => DeliveryStatus::PENDING,
                    'provider' => $channel === 'mail' ? (string) config('mail.default', 'smtp') : 'database',
                    'provider_message_id' => null,
                    'payload' => [
                        'event_key' => $eventKey,
                        'category' => $category,
                        'priority' => $priority,
                        'title' => $title,
                        'message' => $message,
                        'reservation_id' => $reservationId,
                        'document_id' => $document?->id,
                        'has_document' => $document !== null,
                        'action_url' => $actionUrl,
                    ],
                    'sent_at' => null,
                    'failed_at' => null,
                    'error_message' => null,
                ]);
                $delivery->save();

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
                        channels: [$channel],
                        reason: $reason,
                        link: $link
                    );
                    $notification->onQueue($this->queueNameFor($priority, $channel));

                    if ($channel === 'database') {
                        NotificationFacade::sendNow($user, $notification, ['database']);
                    } else {
                        $user->notify($notification);
                    }

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

    private function passesAntiSpam(User $user, string $eventKey, string $channel, int|string|null $scope): bool
    {
        $key = sprintf(
            'notifications:cooldown:%d:%s:%s:%s',
            $user->id,
            $eventKey,
            $channel,
            $scope ?? 'none'
        );

        return Cache::add(
            $key,
            true,
            now()->addMinutes((int) config('notifications.anti_spam.cooldown_minutes', 15))
        );
    }

    private function documentActionUrl(Document $document, ?User $user = null): string
    {
        $token = $this->documentAccessTokenService->issue($document, $user);

        return URL::temporarySignedRoute(
            'api.documents.signed-download',
            now()->addMinutes((int) config('notifications.mail.signed_link_expiration_minutes', 60 * 24)),
            ['document' => $document->id, 'token' => $token->token]
        );
    }

    private function dedupeKey(
        string $eventKey,
        string $channel,
        int $userId,
        ?int $reservationId,
        ?int $documentId,
        string $message
    ): string {
        return hash('sha256', implode('|', [
            $eventKey,
            $channel,
            (string) $userId,
            (string) ($reservationId ?? 0),
            (string) ($documentId ?? 0),
            $message,
        ]));
    }

    private function shouldQueueDigest(User $user): bool
    {
        return $this->preferenceService->digestFrequency($user) !== 'none'
            || $this->preferenceService->inQuietHours($user);
    }

    private function queueDigestItem(
        User $user,
        string $eventKey,
        string $category,
        string $title,
        string $message,
        string $priority,
        ?Reservation $reservation = null,
        ?Document $document = null,
        ?array $link = null
    ): void {
        $frequency = $this->preferenceService->digestFrequency($user);
        $availableAt = match ($frequency) {
            'hourly' => now()->addHour()->startOfHour()->addMinutes((int) config('notifications.digest.hourly_minute', 5)),
            'daily' => now()->addDay()->startOfDay()
                ->addHours((int) config('notifications.digest.daily_hour', 8))
                ->addMinutes((int) config('notifications.digest.daily_minute', 0)),
            default => now()->addMinutes((int) config('notifications.anti_spam.cooldown_minutes', 15)),
        };

        NotificationDigestQueue::create([
            'user_id' => $user->id,
            'event_key' => $eventKey,
            'category' => $category,
            'priority' => $priority,
            'title' => $title,
            'message' => $message,
            'reservation_id' => $reservation?->id,
            'document_id' => $document?->id,
            'payload' => [
                'reservation_id' => $reservation?->id,
                'document_id' => $document?->id,
                'link' => $link,
            ],
            'available_at' => $availableAt,
            'sent_at' => null,
        ]);
    }

    private function queueNameFor(string $priority, string $channel): string
    {
        if ($priority === 'high') {
            return (string) config('queue.names.critical', 'critical');
        }

        if ($channel === 'mail') {
            return (string) config('queue.names.default', 'default');
        }

        return (string) config('queue.names.low', 'low');
    }
}
