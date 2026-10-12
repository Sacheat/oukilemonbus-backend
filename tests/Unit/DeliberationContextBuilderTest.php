<?php

namespace Tests\Unit;

use App\Models\CouncilDecision;
use App\Models\Driver;
use App\Models\Location;
use App\Models\Organization;
use App\Models\Reservation;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Documents\ContextBuilders\DeliberationContextBuilder;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class DeliberationContextBuilderTest extends TestCase
{
    public function test_it_builds_a_complete_deliberation_context(): void
    {
        Carbon::setTestNow('2026-04-20 09:00:00');

        $agent = $this->user(
            id: 1,
            firstName: 'Alice',
            lastName: 'Agent'
        );

        $requester = $this->user(
            id: 2,
            firstName: 'Jean',
            lastName: 'Demandeur'
        );

        $driverUser = $this->user(
            id: 3,
            firstName: 'Marc',
            lastName: 'Chauffeur'
        );

        $organizationLocation = $this->location(
            id: 10,
            formattedAddress: 'Rue de l’Association 12, 5500 Dinant',
            city: 'Dinant'
        );

        $pickupLocation = $this->location(
            id: 11,
            formattedAddress: 'Place Albert 1er, 5500 Dinant',
            city: 'Dinant'
        );

        $destinationLocation = $this->location(
            id: 12,
            formattedAddress: 'Rue de Namur 20, 5000 Namur',
            city: 'Namur'
        );

        $organization = new Organization();
        $organization->forceFill([
            'id' => 100,
            'name' => 'ASBL Solidarité Dinantaise',
            'type' => 'asbl',
            'official_email' => 'contact@asbl.test',
            'phone_contact' => '081000000',
            'name_contact' => 'Responsable ASBL',
            'is_priority' => false,
            'annual_quota' => 5,
            'reservations_used_this_year' => 2,
        ]);
        $organization->setRelation('location', $organizationLocation);

        $driver = new Driver();
        $driver->forceFill([
            'id' => 200,
            'licence_number' => 'DRV-123',
        ]);
        $driver->setRelation('user', $driverUser);

        $vehicle = new Vehicle();
        $vehicle->forceFill([
            'id' => 300,
            'licence_plate' => '1-ABC-123',
            'capacity' => 20,
        ]);

        $reservation = new Reservation();
        $reservation->forceFill([
            'id' => 400,
            'trip_date' => '2026-06-16',
            'departure_time' => '09:00:00',
            'return_time' => '11:00:00',
            'trip_purpose' => 'Sortie éducative',
            'activity_type' => 'standard',
            'nb_children' => 10,
            'nb_adults' => 2,
            'distance_km' => 12,
            'estimated_duration_min' => 30,
            'status' => 'en_attente',
            'approval_type' => 'manuel',
        ]);

        $reservation->setRelation('requestedByUser', $requester);
        $reservation->setRelation('organization', $organization);
        $reservation->setRelation('pickupLocation', $pickupLocation);
        $reservation->setRelation('destinationLocation', $destinationLocation);
        $reservation->setRelation('driver', $driver);
        $reservation->setRelation('vehicle', $vehicle);

        $councilDecision = new CouncilDecision();
        $councilDecision->forceFill([
            'id' => 500,
            'decision_status' => 'approuve',
            'decision_date' => '2026-04-20',
            'decision_reference' => 'COLL-2026-001',
            'validated_at' => '2026-04-20 09:00:00',
            'notes' => 'Décision favorable.',
        ]);

        $councilDecision->setRelation('recordedByUser', $agent);
        $councilDecision->setRelation('reservation', $reservation);

        $builder = new DeliberationContextBuilder();

        $context = $builder->build($councilDecision);

        $this->assertSame('deliberation', $context['document']['type']);
        $this->assertSame('deliberations.subvention', $context['document']['template_key']);
        $this->assertSame('2.0', $context['document']['template_version']);

        $this->assertSame(500, $context['decision']['id']);
        $this->assertSame('approuve', $context['decision']['status']);
        $this->assertSame('2026-04-20', $context['decision']['date']);
        $this->assertSame('COLL-2026-001', $context['decision']['reference']);
        $this->assertSame('Alice Agent', $context['decision']['recorded_by']['full_name']);

        $this->assertSame(400, $context['reservation']['id']);
        $this->assertSame('2026-06-16', $context['reservation']['trip_date']);
        $this->assertSame('09:00', $context['reservation']['departure_time']);
        $this->assertSame('11:00', $context['reservation']['return_time']);
        $this->assertSame('Sortie éducative', $context['reservation']['trip_purpose']);
        $this->assertSame(12, $context['reservation']['total_passengers']);

        $this->assertSame('ASBL Solidarité Dinantaise', $context['organization']['name']);
        $this->assertSame('asbl', $context['organization']['type']);
        $this->assertSame('contact@asbl.test', $context['organization']['official_email']);

        $this->assertSame('Rue de l’Association 12, 5500 Dinant', $context['locations']['organization']['formatted_address']);
        $this->assertSame('Place Albert 1er, 5500 Dinant', $context['locations']['pickup']['formatted_address']);
        $this->assertSame('Rue de Namur 20, 5000 Namur', $context['locations']['destination']['formatted_address']);

        $this->assertSame('Marc Chauffeur', $context['assignment']['driver']['name']);
        $this->assertSame('DRV-123', $context['assignment']['driver']['licence_number']);
        $this->assertSame('1-ABC-123', $context['assignment']['vehicle']['licence_plate']);
        $this->assertSame(20, $context['assignment']['vehicle']['capacity']);

        Carbon::setTestNow();
    }

    public function test_it_does_not_crash_when_driver_and_vehicle_are_missing(): void
    {
        $reservation = new Reservation();
        $reservation->forceFill([
            'id' => 401,
            'nb_children' => 5,
            'nb_adults' => 1,
        ]);

        $reservation->setRelation('requestedByUser', null);
        $reservation->setRelation('organization', null);
        $reservation->setRelation('pickupLocation', null);
        $reservation->setRelation('destinationLocation', null);
        $reservation->setRelation('driver', null);
        $reservation->setRelation('vehicle', null);

        $councilDecision = new CouncilDecision();
        $councilDecision->forceFill([
            'id' => 501,
            'decision_status' => 'approuve',
        ]);

        $councilDecision->setRelation('recordedByUser', null);
        $councilDecision->setRelation('reservation', $reservation);

        $builder = new DeliberationContextBuilder();

        $context = $builder->build($councilDecision);

        $this->assertSame(401, $context['reservation']['id']);
        $this->assertSame(6, $context['reservation']['total_passengers']);
        $this->assertNull($context['assignment']['driver']['id']);
        $this->assertNull($context['assignment']['driver']['name']);
        $this->assertNull($context['assignment']['vehicle']['id']);
        $this->assertNull($context['assignment']['vehicle']['licence_plate']);
    }

    private function user(int $id, string $firstName, string $lastName): User
    {
        $user = new User();
        $user->forceFill([
            'id' => $id,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => strtolower($firstName).'.'.strtolower($lastName).'@test.local',
            'phone' => '000000000',
        ]);

        return $user;
    }

    private function location(int $id, string $formattedAddress, string $city): Location
    {
        $location = new Location();
        $location->forceFill([
            'id' => $id,
            'formatted_address' => $formattedAddress,
            'street' => 'Rue test',
            'street_number' => '1',
            'postal_code' => '5500',
            'city' => $city,
            'country' => 'Belgique',
            'latitude' => 50.2600,
            'longitude' => 4.9100,
        ]);

        return $location;
    }
}
