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

        return response($this->htmlExportContent($reservations, $startDate, $endDate), 200, [
            'Content-Type' => 'application/vnd.ms-excel; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$fileName.'"',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
        ]);
    }

    public function htmlExportContent(Collection $reservations, string $startDate, string $endDate): string
    {
        $rows = $reservations
            ->values()
            ->map(fn (Reservation $reservation, int $index): string => $this->row($reservation, $index))
            ->implode('');

        return '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
</head>
<body>
    <table border="1" cellspacing="0" cellpadding="6" style="border-collapse: collapse; width: 100%; font-family: Arial, sans-serif; font-size: 12px;">
        <thead>
            <tr style="height: 55px;">
                <td colspan="12"
                    align="center"
                    valign="middle"
                    height="55"
                    style="text-align: center; vertical-align: middle; font-size: 22px; font-weight: bold; background-color: #ffffff; padding-top: 18px; padding-bottom: 18px; height: 55px; mso-height-source: userset;">
                    <strong>Planning du '.e($startDate).' au '.e($endDate).'</strong>
                </td>
            </tr>
            <tr>
                '.$this->headerCell('Date').'
                '.$this->headerCell('Depart').'
                '.$this->headerCell('Retour').'
                '.$this->headerCell('Chauffeur').'
                '.$this->headerCell('Vehicule').'
                '.$this->headerCell('Organisation').'
                '.$this->headerCell('Lieu de depart').'
                '.$this->headerCell('Destination').'
                '.$this->headerCell('Passagers').'
                '.$this->headerCell('Objet').'
                '.$this->headerCell('Statut').'
                '.$this->headerCell('Contact').'
            </tr>
        </thead>
        <tbody>
            '.$rows.'
        </tbody>
    </table>
</body>
</html>';
    }

    private function headerCell(string $label): string
    {
        return '<th align="center" valign="middle" style="background-color: #eaeaea; font-weight: bold; font-size: 15px; text-align: center; vertical-align: middle; padding: 8px; height: 24px;">'.e($label).'</th>';
    }

    private function centeredCell(?string $value, string $backgroundColor): string
    {
        return '<td align="center" valign="middle" bgcolor="'.$backgroundColor.'" style="text-align: center; vertical-align: middle; white-space: nowrap; background-color: '.$backgroundColor.';">'.e($value).'</td>';
    }

    private function normalCell(?string $value, string $backgroundColor): string
    {
        return '<td valign="middle" bgcolor="'.$backgroundColor.'" style="text-align: left; vertical-align: middle; background-color: '.$backgroundColor.';">'.e($value).'</td>';
    }

    private function row(Reservation $reservation, int $index): string
    {
        $backgroundColor = $index % 2 === 1 ? '#f3f3f3' : '#ffffff';

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

        $departure = e($reservation->departure_time?->format('H:i'))
            .'<br><span style="font-size: 10px; color: #555;">Occupation '.$occupationStart->format('H:i').'</span>';

        $return = e($reservation->return_time?->format('H:i'))
            .'<br><span style="font-size: 10px; color: #555;">Fin occupation '.$occupationEnd->format('H:i').'</span>';

        $passengers = (string) (($reservation->nb_children ?? 0) + ($reservation->nb_adults ?? 0));

        return '<tr bgcolor="'.$backgroundColor.'" style="background-color: '.$backgroundColor.';">
            '.$this->centeredCell($reservation->trip_date?->format('d/m/Y'), $backgroundColor).'
            <td align="center" valign="middle" bgcolor="'.$backgroundColor.'" style="text-align: center; vertical-align: middle; white-space: nowrap; background-color: '.$backgroundColor.';">'.$departure.'</td>
            <td align="center" valign="middle" bgcolor="'.$backgroundColor.'" style="text-align: center; vertical-align: middle; white-space: nowrap; background-color: '.$backgroundColor.';">'.$return.'</td>
            '.$this->centeredCell($driverName !== '' ? $driverName : 'Non assigne', $backgroundColor).'
            '.$this->centeredCell($reservation->vehicle?->licence_plate ?? 'Non assigne', $backgroundColor).'
            '.$this->normalCell($reservation->organization?->name, $backgroundColor).'
            '.$this->normalCell($reservation->pickupLocation?->formatted_address, $backgroundColor).'
            '.$this->normalCell($reservation->destinationLocation?->formatted_address, $backgroundColor).'
            '.$this->centeredCell($passengers, $backgroundColor).'
            '.$this->normalCell($reservation->trip_purpose, $backgroundColor).'
            '.$this->centeredCell($reservation->status?->value ?? $reservation->status, $backgroundColor).'
            '.$this->normalCell($contact, $backgroundColor).'
        </tr>';
    }
}
