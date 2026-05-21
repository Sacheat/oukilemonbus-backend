<?php

namespace Tests\Unit;

use App\Services\Logging\DomainLogger;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

class DomainLoggerTest extends TestCase
{
    public function test_reservation_conflicts_are_logged_on_reservations_channel(): void
    {
        $channel = Mockery::mock();

        Log::shouldReceive('channel')
            ->once()
            ->with('reservations')
            ->andReturn($channel);

        $channel->shouldReceive('warning')
            ->once()
            ->with('Reservation preview has conflicts.', Mockery::on(
                fn (array $context): bool => $context['organization_id'] === 12
                    && $context['trip_date'] === '2026-06-18'
                    && $context['conflict_types'] === ['schedule_overlap']
                    && $context['related_reservation_ids'] === [34]
                    && $context['availability']['next_available_departure_time'] === '09:35'
            ));

        app(DomainLogger::class)->reservationConflictsDetected(
            [
                'organization_id' => 12,
                'trip_date' => '2026-06-18',
                'departure_time' => '09:00',
                'return_time' => '10:00',
            ],
            [
                [
                    'type' => 'schedule_overlap',
                    'related_reservation_id' => 34,
                ],
            ],
            [
                'next_available_departure_time' => '09:35',
            ]
        );
    }

    public function test_domain_log_channels_are_configured(): void
    {
        $this->assertSame(storage_path('logs/reservations.log'), config('logging.channels.reservations.path'));
        $this->assertSame(storage_path('logs/routing.log'), config('logging.channels.routing.path'));
        $this->assertSame(storage_path('logs/documents.log'), config('logging.channels.documents.path'));
        $this->assertSame(storage_path('logs/jobs.log'), config('logging.channels.jobs.path'));
    }
}
