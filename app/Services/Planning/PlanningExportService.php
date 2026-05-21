<?php

namespace App\Services\Planning;

use App\Models\Reservation;
use App\Services\Reservations\ReservationOccupationService;
use Illuminate\Database\Eloquent\Collection;
use Symfony\Component\HttpFoundation\Response;

class PlanningExportService
{
    public function __construct(
        private readonly ReservationOccupationService $occupationService
    ) {}

    public function download(Collection $reservations, string $startDate, string $endDate): Response
    {
        $fileName = sprintf('planning-%s-%s.xls', $startDate, $endDate);

        return response($this->html($reservations, $startDate, $endDate), 200, [
            'Content-Type' => 'application/vnd.ms-excel; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$fileName.'"',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
        ]);
    }

    private function html(Collection $reservations, string $startDate, string $endDate): string
    {
        $rows = $reservations
            ->map(fn (Reservation $reservation): string => $this->row($reservation))
            ->implode('');

        return '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        table { border-collapse: collapse; width: 100%; }
        th, td { border: 1px solid #999; padding: 6px; vertical-align: top; }
        th { background: #eaeaea; font-weight: bold; }
    </style>
</head>
<body>
    <h1>Planning du '.e($startDate).' au '.e($endDate).'</h1>
    <table>
        <thead>
            <tr>
                <th>Date</th>
                <th>Depart</th>
                <th>Retour</th>
                <th>Chauffeur</th>
                <th>Vehicule</th>
                <th>Organisation</th>
                <th>Lieu de depart</th>
                <th>Destination</th>
                <th>Passagers</th>
                <th>Objet</th>
                <th>Statut</th>
                <th>Contact</th>
            </tr>
        </thead>
        <tbody>
            '.$rows.'
        </tbody>
    </table>
</body>
</html>';
    }

    private function row(Reservation $reservation): string
    {
        $occupation = $this->occupationService->windowForReservation($reservation);
        $occupationStart = $occupation['start'];
        $occupationEnd = $occupation['end'];

        $driverName = trim(
            ($reservation->driver?->user?->first_name ?? '').' '.
            ($reservation->driver?->user?->last_name ?? '')
        );

        $contact = $reservation->organization?->phone_contact
            ?? $reservation->requestedByUser?->phone
            ?? $reservation->organization?->official_email
            ?? $reservation->requestedByUser?->email;

        return '<tr>
            <td>'.e($reservation->trip_date?->format('d/m/Y')).'</td>
            <td>'.e($reservation->departure_time?->format('H:i')).'<br><small>'.e($occupationStart->format('H:i')).'</small></td>
            <td>'.e($reservation->return_time?->format('H:i')).'<br><small>Fin occupation '.e($occupationEnd->format('H:i')).'</small></td>
            <td>'.e($driverName !== '' ? $driverName : 'Non assigne').'</td>
            <td>'.e($reservation->vehicle?->licence_plate ?? 'Non assigne').'</td>
            <td>'.e($reservation->organization?->name).'</td>
            <td>'.e($reservation->pickupLocation?->formatted_address).'</td>
            <td>'.e($reservation->destinationLocation?->formatted_address).'</td>
            <td>'.e((string) (($reservation->nb_children ?? 0) + ($reservation->nb_adults ?? 0))).'</td>
            <td>'.e($reservation->trip_purpose).'</td>
            <td>'.e($reservation->status?->value ?? $reservation->status).'</td>
            <td>'.e($contact).'</td>
        </tr>';
    }
}
