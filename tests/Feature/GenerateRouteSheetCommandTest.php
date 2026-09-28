<?php

namespace Tests\Feature;

use App\Enums\ApprovalType;
use App\Enums\DocumentType;
use App\Enums\OrganizationType;
use App\Enums\ReservationActivityType;
use App\Enums\ReservationStatus;
use App\Models\Document;
use App\Models\Driver;
use App\Models\Location;
use App\Models\Organization;
use App\Models\Reservation;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Documents\RouteSheetDocumentService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GenerateRouteSheetCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_generates_a_route_sheet_document_from_console_command(): void
    {
        $document = Document::create([
            'reservation_id' => null,
            'user_id' => null,
            'document_type' => DocumentType::FEUILLE_ROUTE,
            'file_name' => 'route-sheet-2026-03-02-2026-03-06.pdf',
            'file_path' => 'documents/route-sheets/2026-03-02-2026-03-06/route-sheet-2026-03-02-2026-03-06.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 1234,
            'checksum' => 'fake-checksum',
            'generation_mode' => 'auto',
            'generation_context' => [],
            'template_key' => 'route_sheets.weekly',
            'template_version' => '1.0',
            'generated_at' => now(),
            'is_official' => false,
            'version' => 1,
            'is_available' => true,
        ]);

        $service = $this->createMock(RouteSheetDocumentService::class);

        $service->expects($this->once())
            ->method('generateForPeriod')
            ->with('2026-03-02', '2026-03-06')
            ->willReturn($document);

        $this->app->instance(RouteSheetDocumentService::class, $service);

        $this->artisan('route-sheets:generate', [
            'startDate' => '2026-03-02',
            'endDate' => '2026-03-06',
        ])
            ->expectsOutput('Génération de la feuille de route du 2026-03-02 au 2026-03-06...')
            ->expectsOutput('Feuille de route générée avec succès. Document ID : '.$document->id)
            ->expectsOutput('Fichier : '.$document->file_path)
            ->assertExitCode(0);
    }

    public function test_it_returns_failure_when_period_is_invalid(): void
    {
        $this->artisan('route-sheets:generate', [
            'startDate' => '2026-03-06',
            'endDate' => '2026-03-02',
        ])
            ->expectsOutput('Génération de la feuille de route du 2026-03-06 au 2026-03-02...')
            ->expectsOutput('La date de fin doit être égale ou postérieure à la date de début.')
            ->assertExitCode(1);
    }

    public function test_it_returns_failure_when_date_format_is_invalid(): void
    {
        $this->artisan('route-sheets:generate', [
            'startDate' => 'not-a-date',
            'endDate' => '2026-03-02',
        ])
            ->expectsOutput('Génération de la feuille de route du not-a-date au 2026-03-02...')
            ->expectsOutput('Les dates doivent être valides.')
            ->assertExitCode(1);
    }

    public function test_it_generates_daily_route_sheets_for_confirmed_reservation_vehicles(): void
    {
        $vehicle = $this->createConfirmedReservationForVehicle('2026-06-18');

        $document = $this->makeRouteSheetDocument(
            fileName: 'route-sheet-2026-06-18-1-vjx-649-v001.pdf',
            filePath: 'documents/route-sheets/2026-06-18/vehicle-'.$vehicle->id.'/route-sheet-2026-06-18-1-vjx-649-v001.pdf',
        );

        $service = $this->createMock(RouteSheetDocumentService::class);

        $service->expects($this->once())
            ->method('generateForServiceDateVehicle')
            ->with('2026-06-18', $vehicle->id)
            ->willReturn($document);

        $this->app->instance(RouteSheetDocumentService::class, $service);

        $this->artisan('route-sheets:generate-daily', [
            'serviceDate' => '2026-06-18',
        ])
            ->expectsOutput('Génération des feuilles de route journalières pour le 2026-06-18...')
            ->expectsOutput('Véhicule '.$vehicle->id.' : document '.$document->id.' généré ('.$document->file_path.').')
            ->expectsOutput('Terminé : 1 générée(s), 0 ignorée(s), 0 échec(s).')
            ->assertExitCode(0);
    }

    public function test_it_skips_daily_route_sheet_when_it_already_exists(): void
    {
        $vehicle = $this->createConfirmedReservationForVehicle('2026-06-18');

        $this->createRouteSheetDocument(
            fileName: 'route-sheet-2026-06-18-1-vjx-649-v001.pdf',
            filePath: 'documents/route-sheets/2026-06-18/vehicle-'.$vehicle->id.'/route-sheet-2026-06-18-1-vjx-649-v001.pdf',
        );

        $service = $this->createMock(RouteSheetDocumentService::class);

        $service->expects($this->never())
            ->method('generateForServiceDateVehicle');

        $this->app->instance(RouteSheetDocumentService::class, $service);

        $this->artisan('route-sheets:generate-daily', [
            'serviceDate' => '2026-06-18',
        ])
            ->expectsOutput('Génération des feuilles de route journalières pour le 2026-06-18...')
            ->expectsOutput('Véhicule '.$vehicle->id.' : déjà généré, ignoré.')
            ->expectsOutput('Terminé : 0 générée(s), 1 ignorée(s), 0 échec(s).')
            ->assertExitCode(0);
    }

    public function test_it_can_force_daily_route_sheet_regeneration(): void
    {
        $vehicle = $this->createConfirmedReservationForVehicle('2026-06-18');

        $this->createRouteSheetDocument(
            fileName: 'route-sheet-2026-06-18-1-vjx-649-v001.pdf',
            filePath: 'documents/route-sheets/2026-06-18/vehicle-'.$vehicle->id.'/route-sheet-2026-06-18-1-vjx-649-v001.pdf',
        );

        $forcedDocument = $this->makeRouteSheetDocument(
            fileName: 'route-sheet-2026-06-18-1-vjx-649-v002.pdf',
            filePath: 'documents/route-sheets/2026-06-18/vehicle-'.$vehicle->id.'/route-sheet-2026-06-18-1-vjx-649-v002.pdf',
            version: 2,
        );

        $service = $this->createMock(RouteSheetDocumentService::class);

        $service->expects($this->once())
            ->method('generateForServiceDateVehicle')
            ->with('2026-06-18', $vehicle->id)
            ->willReturn($forcedDocument);

        $this->app->instance(RouteSheetDocumentService::class, $service);

        $this->artisan('route-sheets:generate-daily', [
            'serviceDate' => '2026-06-18',
            '--force' => true,
        ])
            ->expectsOutput('Génération des feuilles de route journalières pour le 2026-06-18 (force)...')
            ->expectsOutput('Véhicule '.$vehicle->id.' : document '.$forcedDocument->id.' généré ('.$forcedDocument->file_path.').')
            ->expectsOutput('Terminé : 1 générée(s), 0 ignorée(s), 0 échec(s).')
            ->assertExitCode(0);
    }

    public function test_daily_route_sheet_command_defaults_to_tomorrow(): void
    {
        CarbonImmutable::setTestNow('2026-06-17 20:00:00');

        $vehicle = $this->createConfirmedReservationForVehicle('2026-06-18');

        $document = $this->makeRouteSheetDocument(
            fileName: 'route-sheet-2026-06-18-1-vjx-649-v001.pdf',
            filePath: 'documents/route-sheets/2026-06-18/vehicle-'.$vehicle->id.'/route-sheet-2026-06-18-1-vjx-649-v001.pdf',
        );

        $service = $this->createMock(RouteSheetDocumentService::class);

        $service->expects($this->once())
            ->method('generateForServiceDateVehicle')
            ->with('2026-06-18', $vehicle->id)
            ->willReturn($document);

        $this->app->instance(RouteSheetDocumentService::class, $service);

        $this->artisan('route-sheets:generate-daily')
            ->expectsOutput('Génération des feuilles de route journalières pour le 2026-06-18...')
            ->assertExitCode(0);

        CarbonImmutable::setTestNow();
    }

    public function test_daily_route_sheet_command_rejects_invalid_service_date(): void
    {
        $this->artisan('route-sheets:generate-daily', [
            'serviceDate' => 'not-a-date',
        ])
            ->expectsOutput('La date de service doit être valide et au format YYYY-MM-DD.')
            ->assertExitCode(1);
    }

    private function createConfirmedReservationForVehicle(string $serviceDate): Vehicle
    {
        $requester = User::factory()->create();
        $driverUser = User::factory()->create([
            'first_name' => 'Bruno',
            'last_name' => 'Chauffeur',
        ]);

        $driver = Driver::query()->create([
            'user_id' => $driverUser->id,
            'licence_number' => 'DRV-'.$serviceDate,
            'licence_expiry_date' => '2030-01-01',
            'is_active' => true,
        ]);

        $vehicle = Vehicle::query()->create([
            'licence_plate' => '1-VJX-649',
            'capacity' => 41,
            'is_active' => true,
        ]);

        $organizationLocation = $this->createLocation('Rue de l Ecole 1, 5500 Dinant');
        $pickupLocation = $this->createLocation('Ecole communale de Dinant, 5500 Dinant');
        $destinationLocation = $this->createLocation('Centre culturel de Dinant, 5500 Dinant');

        $organization = Organization::query()->create([
            'name' => 'Ecole communale de Dinant',
            'type' => OrganizationType::ECOLE_COMMUNALE,
            'official_email' => 'ecole@example.test',
            'phone_contact' => '081000000',
            'name_contact' => 'Direction',
            'location_id' => $organizationLocation->id,
            'is_priority' => true,
            'annual_quota' => 5,
            'reservations_used_this_year' => 0,
            'quota_reset_year' => now()->startOfYear(),
        ]);

        Reservation::query()->create([
            'requested_by_user_id' => $requester->id,
            'organization_id' => $organization->id,
            'pickup_location_id' => $pickupLocation->id,
            'destination_location_id' => $destinationLocation->id,
            'driver_id' => $driver->id,
            'vehicle_id' => $vehicle->id,
            'trip_date' => $serviceDate,
            'departure_time' => '09:00:00',
            'return_time' => '11:00:00',
            'nb_children' => 10,
            'nb_adults' => 1,
            'trip_purpose' => 'Sortie educative',
            'activity_type' => ReservationActivityType::STANDARD,
            'distance_km' => 12.00,
            'estimated_duration_min' => 30,
            'status' => ReservationStatus::CONFIRME,
            'approval_type' => ApprovalType::AUTO,
            'validated_by_user_id' => $requester->id,
            'validated_at' => now(),
        ]);

        return $vehicle;
    }

    private function createLocation(string $address): Location
    {
        return Location::query()->create([
            'formatted_address' => $address,
            'street' => 'Rue de test',
            'street_number' => '1',
            'postal_code' => '5500',
            'city' => 'Dinant',
            'country' => 'BE',
            'latitude' => 50.260000,
            'longitude' => 4.910000,
            'provider' => 'test',
            'is_verified' => true,
        ]);
    }

    private function createRouteSheetDocument(string $fileName, string $filePath, int $version = 1): Document
    {
        return Document::query()->create([
            'reservation_id' => null,
            'user_id' => null,
            'document_type' => DocumentType::FEUILLE_ROUTE,
            'file_name' => $fileName,
            'file_path' => $filePath,
            'mime_type' => 'application/pdf',
            'file_size' => 1234,
            'checksum' => 'fake-checksum-'.$version,
            'generation_mode' => 'auto',
            'generation_context' => [],
            'template_key' => 'route_sheets.daily',
            'template_version' => '1.0',
            'generated_at' => now(),
            'is_official' => false,
            'version' => $version,
            'is_available' => true,
        ]);
    }

    private function makeRouteSheetDocument(string $fileName, string $filePath, int $version = 1): Document
    {
        $document = new Document([
            'reservation_id' => null,
            'user_id' => null,
            'document_type' => DocumentType::FEUILLE_ROUTE,
            'file_name' => $fileName,
            'file_path' => $filePath,
            'mime_type' => 'application/pdf',
            'file_size' => 1234,
            'checksum' => 'fake-checksum-'.$version,
            'generation_mode' => 'auto',
            'generation_context' => [],
            'template_key' => 'route_sheets.daily',
            'template_version' => '1.0',
            'generated_at' => now(),
            'is_official' => false,
            'version' => $version,
            'is_available' => true,
        ]);

        $document->id = 999 + $version;

        return $document;
    }
}
