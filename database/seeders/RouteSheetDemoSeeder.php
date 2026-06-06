<?php

namespace Database\Seeders;

use App\Enums\ApprovalType;
use App\Enums\OrganizationType;
use App\Enums\ReservationActivityType;
use App\Enums\ReservationStatus;
use App\Models\Location;
use App\Models\Organization;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Database\Seeder;

class RouteSheetDemoSeeder extends Seeder
{
    public function run(): void
    {
        $user      = $this->createUser();
        $locations = $this->createLocations();
        $org       = $this->createOrganization($locations['org']);

        $this->createReservations($org, $user, $locations);
    }

    private function createUser(): User
    {
        return User::firstOrCreate(
            ['email' => 'demo.routesheet@example.test'],
            [
                'first_name'        => 'Demo',
                'last_name'         => 'Feuille de route',
                'password'          => bcrypt('password'),
                'phone'             => null,
                'active'            => true,
                'email_verified_at' => now(),
            ]
        );
    }

    private function createLocations(): array
    {
        return [
            'org' => Location::firstOrCreate(
                ['formatted_address' => 'Rue de l Ecole 1, 5500 Dinant'],
                [
                    'street' => 'Rue de l Ecole', 'street_number' => '1',
                    'postal_code' => '5500', 'city' => 'Dinant', 'country' => 'BE',
                    'latitude' => 50.260000, 'longitude' => 4.910000,
                    'provider' => 'demo', 'is_verified' => true,
                ]
            ),
            'pickup' => Location::firstOrCreate(
                ['formatted_address' => 'Ecole communale de Dinant, 5500 Dinant'],
                [
                    'street' => 'Rue Grande', 'street_number' => '10',
                    'postal_code' => '5500', 'city' => 'Dinant', 'country' => 'BE',
                    'latitude' => 50.261000, 'longitude' => 4.912000,
                    'provider' => 'demo', 'is_verified' => true,
                ]
            ),
            'pool' => Location::firstOrCreate(
                ['formatted_address' => 'Piscine communale de Dinant, 5500 Dinant'],
                [
                    'street' => 'Rue de la Piscine', 'street_number' => '3',
                    'postal_code' => '5500', 'city' => 'Dinant', 'country' => 'BE',
                    'latitude' => 50.263000, 'longitude' => 4.914000,
                    'provider' => 'demo', 'is_verified' => true,
                ]
            ),
            'museum' => Location::firstOrCreate(
                ['formatted_address' => 'Musée de Dinant, 5500 Dinant'],
                [
                    'street' => 'Rue du Musée', 'street_number' => '8',
                    'postal_code' => '5500', 'city' => 'Dinant', 'country' => 'BE',
                    'latitude' => 50.264000, 'longitude' => 4.916000,
                    'provider' => 'demo', 'is_verified' => true,
                ]
            ),
            'library' => Location::firstOrCreate(
                ['formatted_address' => 'Bibliothèque communale de Dinant, 5500 Dinant'],
                [
                    'street' => 'Rue de la Bibliothèque', 'street_number' => '12',
                    'postal_code' => '5500', 'city' => 'Dinant', 'country' => 'BE',
                    'latitude' => 50.265000, 'longitude' => 4.918000,
                    'provider' => 'demo', 'is_verified' => true,
                ]
            ),
        ];
    }

    private function createOrganization(Location $orgLocation): Organization
    {
        return Organization::firstOrCreate(
            ['official_email' => 'ecole.demo@example.test'],
            [
                'name'                       => 'Ecole communale de Dinant',
                'type'                       => OrganizationType::ECOLE_COMMUNALE,
                'phone_contact'              => '081000000',
                'name_contact'               => 'Mme Martin',
                'location_id'               => $orgLocation->id,
                'is_priority'               => true,
                'annual_quota'              => 5,
                'reservations_used_this_year' => 0,
                'quota_reset_year'           => now()->startOfYear(),
            ]
        );
    }

    private function createReservations(Organization $org, User $user, array $locations): void
    {
        $tomorrow         = now()->addDay()->format('Y-m-d');
        $dayAfterTomorrow = now()->addDays(2)->format('Y-m-d');
        $validatedAt      = now()->subDays(14);

        Reservation::updateOrCreate(
            [
                'organization_id' => $org->id,
                'trip_date'       => $tomorrow,
                'departure_time'  => '09:00:00',
                'trip_purpose'    => 'Démo feuille de route - sortie piscine',
            ],
            [
                'requested_by_user_id'    => $user->id,
                'pickup_location_id'      => $locations['pickup']->id,
                'destination_location_id' => $locations['pool']->id,
                'return_time'             => '11:00:00',
                'nb_children'             => 10,
                'nb_adults'               => 2,
                'activity_type'           => ReservationActivityType::STANDARD,
                'distance_km'             => 12.50,
                'estimated_duration_min'  => 25,
                'status'                  => ReservationStatus::CONFIRME,
                'approval_type'           => ApprovalType::MANUEL,
                'validated_by_user_id'    => $user->id,
                'validated_at'            => $validatedAt,
            ]
        );

        Reservation::updateOrCreate(
            [
                'organization_id' => $org->id,
                'trip_date'       => $tomorrow,
                'departure_time'  => '13:30:00',
                'trip_purpose'    => 'Démo feuille de route - sortie musée',
            ],
            [
                'requested_by_user_id'    => $user->id,
                'pickup_location_id'      => $locations['pickup']->id,
                'destination_location_id' => $locations['museum']->id,
                'return_time'             => '15:00:00',
                'nb_children'             => 8,
                'nb_adults'               => 1,
                'activity_type'           => ReservationActivityType::STANDARD,
                'distance_km'             => 10.00,
                'estimated_duration_min'  => 20,
                'status'                  => ReservationStatus::CONFIRME,
                'approval_type'           => ApprovalType::MANUEL,
                'validated_by_user_id'    => $user->id,
                'validated_at'            => $validatedAt,
            ]
        );

        Reservation::updateOrCreate(
            [
                'organization_id' => $org->id,
                'trip_date'       => $dayAfterTomorrow,
                'departure_time'  => '10:00:00',
                'trip_purpose'    => 'Démo feuille de route - réservation non confirmée',
            ],
            [
                'requested_by_user_id'    => $user->id,
                'pickup_location_id'      => $locations['pickup']->id,
                'destination_location_id' => $locations['library']->id,
                'return_time'             => '12:00:00',
                'nb_children'             => 6,
                'nb_adults'               => 1,
                'activity_type'           => ReservationActivityType::STANDARD,
                'distance_km'             => 5.00,
                'estimated_duration_min'  => 15,
                'status'                  => ReservationStatus::EN_ATTENTE,
                'approval_type'           => ApprovalType::MANUEL,
                'validated_by_user_id'    => null,
                'validated_at'            => null,
            ]
        );
    }
}
