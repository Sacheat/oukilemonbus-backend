<?php

namespace Database\Seeders;

use App\Enums\ApprovalType;
use App\Enums\ReservationStatus;
use App\Models\Driver;
use App\Models\Location;
use App\Models\Organization;
use App\Models\Reservation;
use App\Models\User;
use App\Models\Vehicle;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

/**
 * Démo « Ma journée » : trois trajets confirmés aujourd'hui pour chauffeur.test@bus-communal.local (mot de passe
 * « password »), placés par rapport à l'heure actuelle pour que le premier départ soit déjà enregistrable.
 * Nécessite les données de TestScenariosSeeder (migrate:fresh --seed).
 *
 *   php artisan db:seed --class=DriverJourneyDemoSeeder
 *
 * Relancer remet la journée à zéro : les trajets de démo du jour sont supprimés (avec leurs étapes, compte rendu et
 * tracé) puis recréés à partir de l'heure actuelle.
 */
class DriverJourneyDemoSeeder extends Seeder
{
    private const PURPOSE_PREFIX = 'Démo Ma journée';

    public function run(): void
    {
        $timezone = config('reservations.local_timezone', 'Europe/Brussels');
        $now = CarbonImmutable::now($timezone);
        $today = $now->toDateString();

        $requester = User::query()->where('email', 'user.test@bus-communal.local')->firstOrFail();
        $agent = User::query()->where('email', 'agent.test@bus-communal.local')->firstOrFail();
        $driver = Driver::query()->where('licence_number', 'DRV-SEED-001')->firstOrFail();
        $vehicle = Vehicle::query()->where('licence_plate', '1-VJX-649')->firstOrFail();
        $school = Organization::query()->where('name', 'Ecole communale de Dinant')->firstOrFail();
        $pickup = Location::query()->where('place_id', 'seed-dinant-pickup')->firstOrFail();

        Reservation::query()
            ->whereDate('trip_date', $today)
            ->where('trip_purpose', 'like', self::PURPOSE_PREFIX.'%')
            ->get()
            ->each->delete();

        // Premier départ au prochain quart d'heure (+15 min) : la fenêtre du chauffeur (60 min avant) est déjà ouverte
        $start = $now->setTime($now->hour, intdiv($now->minute, 15) * 15)->addMinutes(30);
        $endOfDay = $now->setTime(23, 45);

        $trips = [
            ['offset' => 0, 'duration' => 90, 'place' => 'seed-dinant-destination', 'purpose' => 'sortie sportive', 'children' => 12, 'adults' => 2, 'km' => 6.5],
            ['offset' => 150, 'duration' => 90, 'place' => 'seed-anseremme', 'purpose' => 'visite à Anseremme', 'children' => 18, 'adults' => 2, 'km' => 9.0],
            ['offset' => 300, 'duration' => 120, 'place' => 'seed-givet', 'purpose' => 'excursion à Givet', 'children' => 22, 'adults' => 3, 'km' => 21.0],
        ];

        foreach ($trips as $index => $trip) {
            $departure = $start->addMinutes($trip['offset']);
            $return = $departure->addMinutes($trip['duration']);

            // Tard le soir, les trajets suivants ne tiennent plus dans la journée
            if (! $departure->isSameDay($now) || $return->greaterThan($endOfDay)) {
                break;
            }

            Reservation::query()->create([
                'requested_by_user_id' => $requester->id,
                'organization_id' => $school->id,
                'pickup_location_id' => $pickup->id,
                'destination_location_id' => Location::query()->where('place_id', $trip['place'])->firstOrFail()->id,
                'driver_id' => $driver->id,
                'vehicle_id' => $vehicle->id,
                'trip_date' => $today,
                'departure_time' => $departure->format('H:i:s'),
                'return_time' => $return->format('H:i:s'),
                'nb_children' => $trip['children'],
                'nb_adults' => $trip['adults'],
                'trip_purpose' => sprintf('%s %d : %s', self::PURPOSE_PREFIX, $index + 1, $trip['purpose']),
                'distance_km' => $trip['km'],
                'estimated_duration_min' => 30,
                'status' => ReservationStatus::CONFIRME,
                'approval_type' => ApprovalType::MANUEL,
                'validated_by_user_id' => $agent->id,
                'validated_at' => $now->subDay(),
            ]);
        }
    }
}
