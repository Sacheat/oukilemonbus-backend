<?php

namespace App\Services\Reservations;

use App\Models\Reservation;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

class ReservationOccupationService
{
    public function windowForReservation(Reservation $reservation): array
    {
        return $this->windowForData([
            'trip_date' => $reservation->trip_date->toDateString(),
            'departure_time' => $reservation->departure_time->format('H:i:s'),
            'return_time' => $reservation->return_time->format('H:i:s'),
        ], $reservation->estimated_duration_min);
    }

    public function windowForData(array $data, ?int $estimatedDurationMin): array
    {
        $departure = $this->dateTime($data['trip_date'], $data['departure_time']);
        $start = $departure->subMinutes($this->pickupBufferMinutes());
        $returnDeparture = $this->dateTime($data['trip_date'], $data['return_time']);
        $end = $returnDeparture->addMinutes($this->returnOccupationMinutes($estimatedDurationMin));

        return [
            'start' => $start,
            'end' => $end,
            'duration_min' => (int) $start->diffInMinutes($end),
            'return_trip_duration_min' => $estimatedDurationMin,
            'pickup_buffer_min' => $this->pickupBufferMinutes(),
            'dropoff_buffer_min' => $this->dropoffBufferMinutes(),
            'safety_buffer_min' => $this->safetyBufferMinutes(),
            'post_return_buffer_min' => $this->postReturnBufferMinutes(),
        ];
    }

    public function dataWithDepartureTime(array $data, CarbonImmutable $departure): array
    {
        $originalDeparture = $this->dateTime($data['trip_date'], $data['departure_time']);
        $originalReturn = $this->dateTime($data['trip_date'], $data['return_time']);
        $stayDurationMinutes = (int) $originalDeparture->diffInMinutes($originalReturn);

        return [
            ...$data,
            'departure_time' => $departure->format('H:i'),
            'return_time' => $departure->addMinutes($stayDurationMinutes)->format('H:i'),
        ];
    }

    /**
     * Requête SQL qui réplique overlaps() pour éviter de charger toutes les
     * réservations du jour en mémoire. Les buffers sont lus depuis la fenêtre
     * candidate déjà calculée, garantissant la cohérence avec windowForData().
     *
     * @param  list<string>  $statuses
     */
    public function overlappingQuery(array $candidateWindow, string $tripDate, array $statuses, ?int $ignoreId = null): Builder
    {
        $pickupBuffer    = $candidateWindow['pickup_buffer_min'];
        $postReturnBuffer = $candidateWindow['post_return_buffer_min'];

        return Reservation::query()
            ->whereIn('status', $statuses)
            ->whereDate('trip_date', $tripDate)
            ->when($ignoreId !== null, fn (Builder $q) => $q->whereKeyNot($ignoreId))
            // other.window_start < candidate.window_end
            ->whereRaw(
                'TIMESTAMPADD(MINUTE, ?, TIMESTAMP(trip_date, departure_time)) < ?',
                [-$pickupBuffer, $candidateWindow['end']->toDateTimeString()]
            )
            // other.window_end > candidate.window_start
            ->whereRaw(
                'TIMESTAMPADD(MINUTE, COALESCE(estimated_duration_min, 0) + ?, TIMESTAMP(trip_date, return_time)) > ?',
                [$postReturnBuffer, $candidateWindow['start']->toDateTimeString()]
            );
    }

    public function overlaps(array $firstWindow, array $secondWindow): bool
    {
        return $firstWindow['start']->lessThan($secondWindow['end'])
            && $firstWindow['end']->greaterThan($secondWindow['start']);
    }

    public function returnOccupationMinutes(?int $estimatedDurationMin): int
    {
        return ($estimatedDurationMin ?? 0) + $this->postReturnBufferMinutes();
    }

    public function postReturnBufferMinutes(): int
    {
        return $this->dropoffBufferMinutes() + $this->safetyBufferMinutes();
    }

    public function overlapDurationMinutes(array $firstWindow, array $secondWindow): int
    {
        if (! $this->overlaps($firstWindow, $secondWindow)) {
            return 0;
        }

        $start = $firstWindow['start']->greaterThan($secondWindow['start'])
            ? $firstWindow['start']
            : $secondWindow['start'];
        $end = $firstWindow['end']->lessThan($secondWindow['end'])
            ? $firstWindow['end']
            : $secondWindow['end'];

        return (int) $start->diffInMinutes($end);
    }

    public function nextDepartureAfterWindow(array $window): CarbonImmutable
    {
        return $window['end']->addMinutes($this->pickupBufferMinutes());
    }

    private function pickupBufferMinutes(): int
    {
        return (int) config('reservations.pickup_buffer_min', 0);
    }

    private function dropoffBufferMinutes(): int
    {
        return (int) config('reservations.dropoff_buffer_min', config('reservations.duration_margin_min', 15));
    }

    private function safetyBufferMinutes(): int
    {
        return (int) config('reservations.safety_buffer_min', 0);
    }

    private function dateTime(string $date, string $time): CarbonImmutable
    {
        return CarbonImmutable::parse($date.' '.$time);
    }
}
