<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Log;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class QueueFailureLoggingTest extends TestCase
{
    public function test_a_job_that_fails_for_good_is_logged_in_jobs_log_and_main_log(): void
    {
        $job = Mockery::mock(Job::class);
        $job->shouldReceive('resolveName')->andReturn('App\\Jobs\\GeneratePlanningExportJob');
        $job->shouldReceive('getQueue')->andReturn('low');
        $job->shouldReceive('attempts')->andReturn(3);
        $job->shouldReceive('uuid')->andReturn('0b0e7a52-6f0f-4c55-9a43-7a1f1d2c3e4f');

        $expectedContext = fn (array $context): bool => $context['job'] === 'App\\Jobs\\GeneratePlanningExportJob'
            && $context['queue'] === 'low'
            && $context['attempts'] === 3
            && $context['message'] === 'Export impossible';

        Log::shouldReceive('channel')->once()->with('jobs')->andReturnSelf();
        Log::shouldReceive('error')->twice()->withArgs(
            fn (string $message, array $context): bool => $message === 'queue.job.failed' && $expectedContext($context)
        );

        event(new JobFailed('database', $job, new RuntimeException('Export impossible')));
    }

    public function test_scheduled_notifications_health_check_logs_an_alert_when_it_fails(): void
    {
        $event = $this->scheduledEvent('notifications:health --json');

        file_put_contents($event->output, '{"status":"alert","data":{"deliveries_failed_last_24h":42}}'.PHP_EOL);
        $event->exitCode = 1;

        Log::shouldReceive('error')->once()->withArgs(
            fn (string $message, array $context): bool => $message === 'notifications.health.alert'
                && $context['report']['status'] === 'alert'
                && $context['report']['data']['deliveries_failed_last_24h'] === 42
        );

        $event->callAfterCallbacks($this->app);

        @unlink($event->output);
    }

    public function test_scheduled_notifications_health_check_logs_nothing_when_healthy(): void
    {
        $event = $this->scheduledEvent('notifications:health --json');

        file_put_contents($event->output, '{"status":"ok"}'.PHP_EOL);
        $event->exitCode = 0;

        Log::shouldReceive('error')->never();

        $event->callAfterCallbacks($this->app);

        @unlink($event->output);
    }

    private function scheduledEvent(string $command): Event
    {
        $event = collect($this->app->make(Schedule::class)->events())
            ->first(fn (Event $event): bool => str_ends_with((string) $event->command, $command));

        $this->assertNotNull($event, "Tâche planifiée « {$command} » introuvable.");

        return $event;
    }
}
