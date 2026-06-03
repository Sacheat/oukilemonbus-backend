<?php

namespace App\Services\Logging;

use App\Models\Document;
use App\Models\Recurrence;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Throwable;

class DomainLogger
{
    public function reservationCreated(Reservation $reservation, User $actor, array $preview): void
    {
        $this->safe(fn () => $this->reservations()->info('Reservation created.', [
            ...$this->reservationContext($reservation),
            'actor_id' => $actor->id,
            'status' => $reservation->status?->value ?? $reservation->status,
            'conflict_count' => count($preview['conflicts'] ?? []),
            'distance_km' => $preview['distance_km'] ?? null,
            'estimated_duration_min' => $preview['estimated_duration_min'] ?? null,
            'occupation' => $preview['estimated_occupation'] ?? null,
        ]));
    }

    public function reservationUpdated(Reservation $reservation, User $actor, array $preview): void
    {
        $this->safe(fn () => $this->reservations()->info('Reservation updated.', [
            ...$this->reservationContext($reservation),
            'actor_id' => $actor->id,
            'status' => $reservation->status?->value ?? $reservation->status,
            'conflict_count' => count($preview['conflicts'] ?? []),
            'distance_km' => $preview['distance_km'] ?? null,
            'estimated_duration_min' => $preview['estimated_duration_min'] ?? null,
            'occupation' => $preview['estimated_occupation'] ?? null,
        ]));
    }

    public function reservationStatusChanged(
        Reservation $reservation,
        User $actor,
        string $oldStatus,
        string $newStatus,
        string $reason
    ): void {
        $this->safe(fn () => $this->reservations()->info('Reservation status changed.', [
            ...$this->reservationContext($reservation),
            'actor_id' => $actor->id,
            'old_status' => $oldStatus,
            'new_status' => $newStatus,
            'reason' => $reason,
        ]));
    }

    public function reservationConflictsDetected(array $data, array $conflicts, array $availability): void
    {
        $this->safe(fn () => $this->reservations()->warning('Reservation preview has conflicts.', [
            'organization_id' => $data['organization_id'] ?? null,
            'trip_date' => $data['trip_date'] ?? null,
            'departure_time' => $data['departure_time'] ?? null,
            'return_time' => $data['return_time'] ?? null,
            'ignore_reservation_id' => $data['ignore_reservation_id'] ?? null,
            'conflict_types' => collect($conflicts)->pluck('type')->values()->all(),
            'related_reservation_ids' => collect($conflicts)->pluck('related_reservation_id')->filter()->values()->all(),
            'availability' => $availability,
        ]));
    }

    public function routeDistanceCalculated(array $context): void
    {
        $this->safe(fn () => $this->routing()->info('Route distance calculated.', $context));
    }

    public function routeDistanceUnavailable(array $context): void
    {
        $this->safe(fn () => $this->routing()->warning('Route distance unavailable.', $context));
    }

    public function routeDistanceCalculationFailed(array $context, Throwable $exception): void
    {
        $this->safe(fn () => $this->routing()->error('Route distance calculation failed.', [
            ...$context,
            'exception_class' => $exception::class,
            'exception_message' => $exception->getMessage(),
        ]));
    }

    public function routeSheetGenerationStarted(array $context): void
    {
        $this->safe(fn () => $this->documents()->info('Route sheet generation started.', $context));
    }

    public function routeSheetGenerationSucceeded(Document $document, array $context): void
    {
        $this->safe(fn () => $this->documents()->info('Route sheet generation succeeded.', [
            ...$context,
            'document_id' => $document->id,
            'file_path' => $document->file_path,
            'version' => $document->version,
        ]));
    }

    public function routeSheetGenerationFailed(array $context, Throwable $exception): void
    {
        $this->safe(fn () => $this->documents()->error('Route sheet generation failed.', [
            ...$context,
            'exception_class' => $exception::class,
            'exception_message' => $exception->getMessage(),
        ]));
    }

    public function routeSheetSkipped(array $context): void
    {
        $this->safe(fn () => $this->jobs()->notice('Route sheet generation skipped.', $context));
    }

    public function dailyRouteSheetsStarted(array $context): void
    {
        $this->safe(fn () => $this->jobs()->info('Daily route sheets job started.', $context));
    }

    public function dailyRouteSheetsFinished(array $context): void
    {
        $this->safe(fn () => $this->jobs()->info('Daily route sheets job finished.', $context));
    }

    public function deliberationGenerationStarted(array $context): void
    {
        $this->safe(fn () => $this->documents()->info('Deliberation generation started.', $context));
    }

    public function deliberationGenerationSucceeded(Document $document, array $context): void
    {
        $this->safe(fn () => $this->documents()->info('Deliberation generation succeeded.', [
            ...$context,
            'document_id' => $document->id,
            'file_path' => $document->file_path,
            'version' => $document->version,
        ]));
    }

    public function recurrenceCreated(Recurrence $recurrence, User $actor, int $validCount, int $skippedCount): void
    {
        $this->safe(fn () => $this->reservations()->info('Recurrence created.', [
            'recurrence_id'      => $recurrence->id,
            'actor_id'           => $actor->id,
            'frequency'          => $recurrence->frequency,
            'start_date'         => $recurrence->start_date?->toDateString(),
            'end_date'           => $recurrence->end_date?->toDateString(),
            'valid_occurrences'  => $validCount,
            'skipped_occurrences' => $skippedCount,
        ]));
    }

    public function recurrenceCancelled(Recurrence $recurrence, User $actor, int $cancelledCount): void
    {
        $this->safe(fn () => $this->reservations()->info('Recurrence cancelled.', [
            'recurrence_id'   => $recurrence->id,
            'actor_id'        => $actor->id,
            'cancelled_count' => $cancelledCount,
        ]));
    }

    public function deliberationGenerationFailed(array $context, Throwable $exception): void
    {
        $this->safe(fn () => $this->documents()->error('Deliberation generation failed.', [
            ...$context,
            'exception_class' => $exception::class,
            'exception_message' => $exception->getMessage(),
        ]));
    }

    private function reservationContext(Reservation $reservation): array
    {
        return [
            'reservation_id' => $reservation->id,
            'organization_id' => $reservation->organization_id,
            'pickup_location_id' => $reservation->pickup_location_id,
            'destination_location_id' => $reservation->destination_location_id,
            'driver_id' => $reservation->driver_id,
            'vehicle_id' => $reservation->vehicle_id,
            'trip_date' => $this->dateValue($reservation->trip_date),
            'departure_time' => $reservation->departure_time,
            'return_time' => $reservation->return_time,
        ];
    }

    private function dateValue(mixed $date): mixed
    {
        return is_object($date) && method_exists($date, 'toDateString')
            ? $date->toDateString()
            : $date;
    }

    private function reservations(): mixed
    {
        return Log::channel('reservations');
    }

    private function routing(): mixed
    {
        return Log::channel('routing');
    }

    private function documents(): mixed
    {
        return Log::channel('documents');
    }

    private function jobs(): mixed
    {
        return Log::channel('jobs');
    }

    private function safe(callable $fn): void
    {
        try {
            $fn();
        } catch (Throwable) {
            // Log failures must never crash the application
        }
    }
}
