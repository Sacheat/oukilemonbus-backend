<?php

namespace App\Console\Commands;

use App\Jobs\SendUserDigestJob;
use App\Models\NotificationDigestQueue;
use Illuminate\Console\Command;

class SendNotificationDigestsCommand extends Command
{
    protected $signature = 'notifications:send-digests';

    protected $description = 'Envoie les notifications digest en attente';

    public function handle(): int
    {
        $userIds = NotificationDigestQueue::query()
            ->whereNull('sent_at')
            ->where(function ($query): void {
                $query->whereNull('available_at')
                    ->orWhere('available_at', '<=', now());
            })
            ->distinct()
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        foreach ($userIds as $userId) {
            SendUserDigestJob::dispatch($userId);
        }

        $this->info('Digest jobs dispatched: '.count($userIds));

        return self::SUCCESS;
    }
}
