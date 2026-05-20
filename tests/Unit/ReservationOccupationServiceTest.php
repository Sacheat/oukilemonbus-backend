<?php

namespace Tests\Unit;

use App\Services\Reservations\ReservationOccupationService;
use Tests\TestCase;

class ReservationOccupationServiceTest extends TestCase
{
    public function test_it_calculates_the_estimated_bus_occupation_window(): void
    {
        config([
            'reservations.pickup_buffer_min' => 0,
            'reservations.dropoff_buffer_min' => 10,
            'reservations.safety_buffer_min' => 5,
        ]);

        $window = (new ReservationOccupationService)->windowForData([
            'trip_date' => '2026-06-16',
            'departure_time' => '08:00',
            'return_time' => '08:40',
        ], 25);

        $this->assertSame('2026-06-16 08:00:00', $window['start']->format('Y-m-d H:i:s'));
        $this->assertSame('2026-06-16 09:20:00', $window['end']->format('Y-m-d H:i:s'));
        $this->assertSame(80, $window['duration_min']);
        $this->assertSame(25, $window['return_trip_duration_min']);
        $this->assertSame(0, $window['pickup_buffer_min']);
        $this->assertSame(15, $window['post_return_buffer_min']);
    }

    public function test_it_can_start_the_occupation_before_the_requested_departure(): void
    {
        config([
            'reservations.pickup_buffer_min' => 10,
            'reservations.dropoff_buffer_min' => 10,
            'reservations.safety_buffer_min' => 5,
        ]);

        $window = (new ReservationOccupationService)->windowForData([
            'trip_date' => '2026-06-16',
            'departure_time' => '08:00',
            'return_time' => '08:40',
        ], 25);

        $this->assertSame('2026-06-16 07:50:00', $window['start']->format('Y-m-d H:i:s'));
        $this->assertSame('2026-06-16 09:20:00', $window['end']->format('Y-m-d H:i:s'));
        $this->assertSame(90, $window['duration_min']);
        $this->assertSame(10, $window['pickup_buffer_min']);
    }

    public function test_it_detects_overlapping_occupation_windows(): void
    {
        config([
            'reservations.pickup_buffer_min' => 0,
            'reservations.dropoff_buffer_min' => 10,
            'reservations.safety_buffer_min' => 5,
        ]);

        $service = new ReservationOccupationService;

        $firstWindow = $service->windowForData([
            'trip_date' => '2026-06-16',
            'departure_time' => '08:00',
            'return_time' => '08:40',
        ], 25);

        $secondWindow = $service->windowForData([
            'trip_date' => '2026-06-16',
            'departure_time' => '09:00',
            'return_time' => '10:00',
        ], 25);

        $this->assertTrue($service->overlaps($firstWindow, $secondWindow));
    }
}
