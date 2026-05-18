<?php

namespace App\Services\Planning;

use App\Models\Reservation;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

class ReservationTimeWindowService
{
    /**
     * Retourne le debut reel d'occupation du bus pour une reservation.
     */
    public function occupationStart(Reservation $reservation): CarbonImmutable
    {
        return $this->dateTime(
            $this->formatDate($reservation->trip_date),
            $this->formatTime($reservation->departure_time)
        );
    }

    /**
     * Retourne la fin reelle d'occupation du bus.
     *
     * Attention : return_time represente l'heure de prise en charge retour.
     * On ajoute donc la duree estimee du retour + la marge configurable.
     */
    public function occupationEnd(Reservation $reservation): CarbonImmutable
    {
        return $this->returnDeparture($reservation)
            ->addMinutes($this->durationWithMargin($reservation->estimated_duration_min));
    }

    /**
     * Retourne [debutOccupation, finOccupation].
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function window(Reservation $reservation): array
    {
        return [
            $this->occupationStart($reservation),
            $this->occupationEnd($reservation),
        ];
    }

    /**
     * Retourne la fenetre d'occupation a partir d'un payload non encore sauvegarde.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function windowFromData(string $tripDate, string $departureTime, string $returnTime, ?int $estimatedDurationMin): array
    {
        return [
            $this->dateTime($tripDate, $departureTime),
            $this->occupationEndFromData($tripDate, $returnTime, $estimatedDurationMin),
        ];
    }

    public function departureFromData(string $tripDate, string $departureTime): CarbonImmutable
    {
        return $this->dateTime($tripDate, $departureTime);
    }

    public function returnDepartureFromData(string $tripDate, string $returnTime): CarbonImmutable
    {
        return $this->dateTime($tripDate, $returnTime);
    }

    public function occupationEndFromData(string $tripDate, string $returnTime, ?int $estimatedDurationMin): CarbonImmutable
    {
        return $this->returnDepartureFromData($tripDate, $returnTime)
            ->addMinutes($this->durationWithMargin($estimatedDurationMin));
    }

    public function overlaps(Reservation $first, Reservation $second): bool
    {
        [$firstStart, $firstEnd] = $this->window($first);
        [$secondStart, $secondEnd] = $this->window($second);

        return $this->overlapsWindow($firstStart, $firstEnd, $secondStart, $secondEnd);
    }

    public function reservationOverlapsWindow(Reservation $reservation, CarbonImmutable $start, CarbonImmutable $end): bool
    {
        [$reservationStart, $reservationEnd] = $this->window($reservation);

        return $this->overlapsWindow($reservationStart, $reservationEnd, $start, $end);
    }

    public function overlapsWindow(
        CarbonImmutable $firstStart,
        CarbonImmutable $firstEnd,
        CarbonImmutable $secondStart,
        CarbonImmutable $secondEnd
    ): bool {
        return $firstStart->lessThan($secondEnd) && $firstEnd->greaterThan($secondStart);
    }

    private function returnDeparture(Reservation $reservation): CarbonImmutable
    {
        return $this->dateTime(
            $this->formatDate($reservation->trip_date),
            $this->formatTime($reservation->return_time)
        );
    }

    private function durationWithMargin(?int $estimatedDurationMin): int
    {
        return ($estimatedDurationMin ?? 0) + (int) config('reservations.duration_margin_min', 15);
    }

    private function dateTime(string $date, string $time): CarbonImmutable
    {
        return CarbonImmutable::parse($date.' '.$time);
    }

    private function formatDate(mixed $date): string
    {
        if ($date instanceof CarbonInterface) {
            return $date->toDateString();
        }

        return CarbonImmutable::parse((string) $date)->toDateString();
    }

    private function formatTime(mixed $time): string
    {
        if ($time instanceof CarbonInterface) {
            return $time->format('H:i:s');
        }

        return CarbonImmutable::parse((string) $time)->format('H:i:s');
    }
}
