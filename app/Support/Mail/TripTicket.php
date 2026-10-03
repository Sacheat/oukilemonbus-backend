<?php

namespace App\Support\Mail;

use App\Models\Location;
use App\Models\Reservation;
use App\Services\Reservations\ReservationOccupationService;
use Carbon\CarbonInterface;

/**
 * Billet du trajet affiché dans les emails (complet pour l'usager et le chauffeur, résumé pour un agent).
 * Données prêtes à afficher : le gabarit ne fait aucun calcul. Les heures sont des heures murales de Bruxelles,
 * les arrivées estimées viennent de ReservationOccupationService (comme `estimated_occupation` de l'API) et sont
 * arrondies à 5 minutes au plus proche, comme dans le front.
 */
final class TripTicket
{
    /**
     * @param  list<array{time: string, label: string, place: string}>  $legs
     * @param  list<string>  $chips  passagers, bus et chauffeur (ces deux derniers seulement s'ils sont affectés)
     */
    public function __construct(
        public readonly int $number,
        public readonly ?string $organization,
        public readonly string $dateLong,
        public readonly array $legs,
        public readonly array $chips,
        public readonly string $summaryWhen,
        public readonly string $summaryRoute,
    ) {}

    public static function for(Reservation $reservation): self
    {
        $reservation->loadMissing(['pickupLocation', 'destinationLocation', 'vehicle', 'driver.user', 'organization']);

        $window = app(ReservationOccupationService::class)->windowForReservation($reservation);
        $pickup = self::place($reservation->pickupLocation);
        $destination = self::place($reservation->destinationLocation);
        $departure = $reservation->departure_time->format('H:i');
        $return = $reservation->return_time?->format('H:i');
        $outboundArrival = $window['outbound_arrival'] === null ? null : self::roundedTime($window['outbound_arrival']);
        $returnArrival = $window['return_arrival'] === null ? null : self::roundedTime($window['return_arrival']);

        $legs = [['time' => $departure, 'label' => 'Départ', 'place' => $pickup]];

        if ($outboundArrival !== null) {
            $legs[] = ['time' => $outboundArrival, 'label' => 'Arrivée estimée', 'place' => $destination];
        }

        if ($return !== null) {
            $legs[] = [
                'time' => $return,
                'label' => $returnArrival !== null ? "Retour, arrivée vers {$returnArrival}" : 'Retour',
                'place' => $pickup,
            ];
        }

        $passengers = (int) $reservation->nb_children + (int) $reservation->nb_adults;
        $chips = [$passengers.' '.($passengers > 1 ? 'passagers' : 'passager')];

        if ($reservation->vehicle !== null) {
            $chips[] = "Bus {$reservation->vehicle->licence_plate}";
        }

        $driver = $reservation->driver?->user;

        if ($driver !== null) {
            $chips[] = trim($driver->first_name.' '.mb_substr((string) $driver->last_name, 0, 1)).'.';
        }

        $dateLong = self::longDate($reservation->trip_date);

        return new self(
            number: $reservation->id,
            organization: $reservation->organization?->name,
            dateLong: $dateLong,
            legs: $legs,
            chips: $chips,
            summaryWhen: $dateLong.' · '.$departure.' → '.($return ?? $outboundArrival ?? $departure),
            summaryRoute: $pickup.' → '.$destination.($reservation->vehicle !== null ? " · Bus {$reservation->vehicle->licence_plate}" : ''),
        );
    }

    /** « Jeudi 15 octobre 2026 » (première lettre en majuscule). */
    public static function longDate(CarbonInterface $date): string
    {
        $text = $date->locale('fr')->translatedFormat('l j F Y');

        return mb_strtoupper(mb_substr($text, 0, 1)).mb_substr($text, 1);
    }

    /** Heure arrondie à 5 minutes au plus proche (report sur l'heure suivante si besoin), comme `formatApproxTime` du front. */
    public static function roundedTime(CarbonInterface $time): string
    {
        $minutes = (int) (round($time->minute / 5) * 5);

        return $time->copy()->setTime($time->hour, 0)->addMinutes($minutes)->format('H:i');
    }

    /** Libellé court d'un lieu, comme `shortAddress` du front : rue et numéro, ville, sinon l'adresse complète. */
    public static function place(?Location $location): string
    {
        if ($location === null) {
            return '';
        }

        $street = trim(implode(' ', array_filter([$location->street, $location->street_number])));
        $parts = array_filter([$street, $location->city]);

        return $parts !== [] ? implode(', ', $parts) : (string) $location->formatted_address;
    }
}
