<?php

namespace Tests\Unit;

use App\Enums\DocumentType;
use App\Models\Document;
use App\Models\Reservation;
use App\Services\Documents\ContextBuilders\RouteSheetContextBuilder;
use App\Services\Documents\DocumentService;
use App\Services\Documents\Generators\RouteSheetGenerator;
use App\Services\Documents\RouteSheetDocumentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use App\Enums\ApprovalType;
use App\Enums\OrganizationType;
use App\Enums\ReservationActivityType;
use App\Enums\ReservationStatus;
use App\Models\Location;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

class RouteSheetDocumentServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_generates_and_registers_a_route_sheet_document_for_a_period(): void
    {
        $user = User::factory()->create();

        $organizationLocation = Location::create([
            'formatted_address' => 'Rue de l Ecole 1, 5500 Dinant',
            'street' => 'Rue de l Ecole',
            'street_number' => '1',
            'postal_code' => '5500',
            'city' => 'Dinant',
            'country' => 'BE',
            'latitude' => 50.260000,
            'longitude' => 4.910000,
            'provider' => 'test',
            'is_verified' => true,
        ]);

        $pickupLocation = Location::create([
            'formatted_address' => 'Ecole communale de Dinant, 5500 Dinant',
            'street' => 'Rue Grande',
            'street_number' => '10',
            'postal_code' => '5500',
            'city' => 'Dinant',
            'country' => 'BE',
            'latitude' => 50.261000,
            'longitude' => 4.912000,
            'provider' => 'test',
            'is_verified' => true,
        ]);

        $destinationLocation = Location::create([
            'formatted_address' => 'Centre culturel de Dinant, 5500 Dinant',
            'street' => 'Rue du Centre',
            'street_number' => '5',
            'postal_code' => '5500',
            'city' => 'Dinant',
            'country' => 'BE',
            'latitude' => 50.263000,
            'longitude' => 4.914000,
            'provider' => 'test',
            'is_verified' => true,
        ]);

        $organization = Organization::create([
            'name' => 'Ecole communale de Dinant',
            'type' => OrganizationType::ECOLE_COMMUNALE,
            'official_email' => 'ecole@example.test',
            'phone_contact' => '081000000',
            'name_contact' => 'Mme Martin',
            'location_id' => $organizationLocation->id,
            'is_priority' => true,
            'annual_quota' => 5,
            'reservations_used_this_year' => 0,
            'quota_reset_year' => now()->startOfYear(),
        ]);

        $firstReservation = Reservation::create([
            'requested_by_user_id' => $user->id,
            'organization_id' => $organization->id,
            'pickup_location_id' => $pickupLocation->id,
            'destination_location_id' => $destinationLocation->id,
            'trip_date' => '2026-03-03',
            'departure_time' => '09:00:00',
            'return_time' => '11:00:00',
            'nb_children' => 10,
            'nb_adults' => 2,
            'trip_purpose' => 'Sortie piscine',
            'activity_type' => ReservationActivityType::STANDARD,
            'distance_km' => 12.50,
            'estimated_duration_min' => 25,
            'status' => ReservationStatus::CONFIRME,
            'approval_type' => ApprovalType::MANUEL,
            'validated_by_user_id' => $user->id,
            'validated_at' => '2026-02-20 10:15:00',
        ]);

        $secondReservation = Reservation::create([
            'requested_by_user_id' => $user->id,
            'organization_id' => $organization->id,
            'pickup_location_id' => $pickupLocation->id,
            'destination_location_id' => $destinationLocation->id,
            'trip_date' => '2026-03-03',
            'departure_time' => '13:30:00',
            'return_time' => '15:00:00',
            'nb_children' => 8,
            'nb_adults' => 1,
            'trip_purpose' => 'Sortie musée',
            'activity_type' => ReservationActivityType::STANDARD,
            'distance_km' => 10.00,
            'estimated_duration_min' => 20,
            'status' => ReservationStatus::CONFIRME,
            'approval_type' => ApprovalType::MANUEL,
            'validated_by_user_id' => $user->id,
            'validated_at' => '2026-02-20 10:15:00',
        ]);

        $context = [
            'document' => [
                'type' => DocumentType::FEUILLE_ROUTE->value,
                'label' => DocumentType::FEUILLE_ROUTE->label(),
                'template_key' => 'route_sheets.weekly',
                'template_version' => '1.0',
            ],
            'period' => [
                'start_date' => '2026-03-02',
                'end_date' => '2026-03-06',
                'label' => 'du 02/03/2026 au 06/03/2026',
            ],
            'rows' => [
                [
                    'reservation_id' => $firstReservation->id,
                ],
                [
                    'reservation_id' => $secondReservation->id,
                ],
            ],
        ];

        $pdfContent = '%PDF fake route sheet content';

        $createdDocument = Document::query()->create([
            'reservation_id' => null,
            'user_id' => null,
            'document_type' => DocumentType::FEUILLE_ROUTE,
            'file_name' => 'route-sheet-2026-03-02-2026-03-06.pdf',
            'file_path' => 'documents/route-sheets/2026-03-02-2026-03-06/route-sheet-2026-03-02-2026-03-06.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => strlen($pdfContent),
            'checksum' => hash('sha256', $pdfContent),
            'generation_mode' => 'auto',
            'generation_context' => $context,
            'template_key' => 'route_sheets.weekly',
            'template_version' => '1.0',
            'generated_at' => now(),
            'is_official' => false,
            'version' => 1,
            'is_available' => true,
        ]);

        $contextBuilder = $this->createMock(RouteSheetContextBuilder::class);
        $generator = $this->createMock(RouteSheetGenerator::class);
        $documentService = $this->createMock(DocumentService::class);

        $contextBuilder->expects($this->once())
            ->method('buildForPeriod')
            ->with('2026-03-02', '2026-03-06')
            ->willReturn($context);

        $generator->expects($this->once())
            ->method('generatePdf')
            ->with($context)
            ->willReturn($pdfContent);

        $generator->expects($this->once())
            ->method('defaultPdfFileName')
            ->with($context)
            ->willReturn('route-sheet-2026-03-02-2026-03-06.pdf');

        $documentService->expects($this->once())
            ->method('registerGeneratedDocument')
            ->with(
                DocumentType::FEUILLE_ROUTE,
                'documents/route-sheets/2026-03-02-2026-03-06',
                'route-sheet-2026-03-02-2026-03-06.pdf',
                $pdfContent,
                $this->callback(function (array $data) use ($context): bool {
                    $this->assertNull($data['reservation_id']);
                    $this->assertSame('auto', $data['generation_mode']);
                    $this->assertSame($context, $data['generation_context']);
                    $this->assertSame('route_sheets.weekly', $data['template_key']);
                    $this->assertSame('1.0', $data['template_version']);
                    $this->assertFalse($data['is_official']);
                    $this->assertSame(1, $data['version']);
                    $this->assertTrue($data['is_available']);

                    return true;
                })
            )
            ->willReturn($createdDocument);

        $service = new RouteSheetDocumentService(
            contextBuilder: $contextBuilder,
            generator: $generator,
            documentService: $documentService
        );

        $document = $service->generateForPeriod('2026-03-02', '2026-03-06');

        $this->assertSame($createdDocument->id, $document->id);

        $reservations = $document->routeSheetReservations()->get();

        $this->assertCount(2, $reservations);

        $this->assertSame($firstReservation->id, $reservations[0]->id);
        $this->assertSame(1, $reservations[0]->pivot->position);

        $this->assertSame($secondReservation->id, $reservations[1]->id);
        $this->assertSame(2, $reservations[1]->pivot->position);
    }

    public function test_it_generates_a_route_sheet_document_without_reservations(): void
    {
        $context = [
            'document' => [
                'type' => DocumentType::FEUILLE_ROUTE->value,
                'label' => DocumentType::FEUILLE_ROUTE->label(),
                'template_key' => 'route_sheets.weekly',
                'template_version' => '1.0',
            ],
            'period' => [
                'start_date' => '2026-03-02',
                'end_date' => '2026-03-06',
                'label' => 'du 02/03/2026 au 06/03/2026',
            ],
            'rows' => [],
        ];

        $pdfContent = '%PDF fake empty route sheet content';

        $createdDocument = Document::query()->create([
            'reservation_id' => null,
            'user_id' => null,
            'document_type' => DocumentType::FEUILLE_ROUTE,
            'file_name' => 'route-sheet-2026-03-02-2026-03-06.pdf',
            'file_path' => 'documents/route-sheets/2026-03-02-2026-03-06/route-sheet-2026-03-02-2026-03-06.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => strlen($pdfContent),
            'checksum' => hash('sha256', $pdfContent),
            'generation_mode' => 'auto',
            'generation_context' => $context,
            'template_key' => 'route_sheets.weekly',
            'template_version' => '1.0',
            'generated_at' => now(),
            'is_official' => false,
            'version' => 1,
            'is_available' => true,
        ]);

        $contextBuilder = $this->createMock(RouteSheetContextBuilder::class);
        $generator = $this->createMock(RouteSheetGenerator::class);
        $documentService = $this->createMock(DocumentService::class);

        $contextBuilder->expects($this->once())
            ->method('buildForPeriod')
            ->with('2026-03-02', '2026-03-06')
            ->willReturn($context);

        $generator->expects($this->once())
            ->method('generatePdf')
            ->with($context)
            ->willReturn($pdfContent);

        $generator->expects($this->once())
            ->method('defaultPdfFileName')
            ->with($context)
            ->willReturn('route-sheet-2026-03-02-2026-03-06.pdf');

        $documentService->expects($this->once())
            ->method('registerGeneratedDocument')
            ->with(
                DocumentType::FEUILLE_ROUTE,
                'documents/route-sheets/2026-03-02-2026-03-06',
                'route-sheet-2026-03-02-2026-03-06.pdf',
                $pdfContent,
                $this->callback(function (array $data) use ($context): bool {
                    $this->assertNull($data['reservation_id']);
                    $this->assertSame('auto', $data['generation_mode']);
                    $this->assertSame($context, $data['generation_context']);
                    $this->assertSame('route_sheets.weekly', $data['template_key']);
                    $this->assertSame('1.0', $data['template_version']);
                    $this->assertFalse($data['is_official']);
                    $this->assertSame(1, $data['version']);
                    $this->assertTrue($data['is_available']);

                    return true;
                })
            )
            ->willReturn($createdDocument);

        $service = new RouteSheetDocumentService(
            contextBuilder: $contextBuilder,
            generator: $generator,
            documentService: $documentService
        );

        $document = $service->generateForPeriod('2026-03-02', '2026-03-06');

        $this->assertSame($createdDocument->id, $document->id);

        $this->assertCount(0, $document->routeSheetReservations()->get());
    }

    public function test_it_generates_a_real_route_sheet_document_with_real_services(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();

        $organizationLocation = Location::create([
            'formatted_address' => 'Rue de l Ecole 1, 5500 Dinant',
            'street' => 'Rue de l Ecole',
            'street_number' => '1',
            'postal_code' => '5500',
            'city' => 'Dinant',
            'country' => 'BE',
            'latitude' => 50.260000,
            'longitude' => 4.910000,
            'provider' => 'test',
            'is_verified' => true,
        ]);

        $pickupLocation = Location::create([
            'formatted_address' => 'Ecole communale de Dinant, 5500 Dinant',
            'street' => 'Rue Grande',
            'street_number' => '10',
            'postal_code' => '5500',
            'city' => 'Dinant',
            'country' => 'BE',
            'latitude' => 50.261000,
            'longitude' => 4.912000,
            'provider' => 'test',
            'is_verified' => true,
        ]);

        $destinationLocation = Location::create([
            'formatted_address' => 'Centre culturel de Dinant, 5500 Dinant',
            'street' => 'Rue du Centre',
            'street_number' => '5',
            'postal_code' => '5500',
            'city' => 'Dinant',
            'country' => 'BE',
            'latitude' => 50.263000,
            'longitude' => 4.914000,
            'provider' => 'test',
            'is_verified' => true,
        ]);

        $organization = Organization::create([
            'name' => 'Ecole communale de Dinant',
            'type' => OrganizationType::ECOLE_COMMUNALE,
            'official_email' => 'ecole@example.test',
            'phone_contact' => '081000000',
            'name_contact' => 'Mme Martin',
            'location_id' => $organizationLocation->id,
            'is_priority' => true,
            'annual_quota' => 5,
            'reservations_used_this_year' => 0,
            'quota_reset_year' => now()->startOfYear(),
        ]);

        $firstReservation = Reservation::create([
            'requested_by_user_id' => $user->id,
            'organization_id' => $organization->id,
            'pickup_location_id' => $pickupLocation->id,
            'destination_location_id' => $destinationLocation->id,
            'trip_date' => '2026-03-03',
            'departure_time' => '09:00:00',
            'return_time' => '11:00:00',
            'nb_children' => 10,
            'nb_adults' => 2,
            'trip_purpose' => 'Sortie piscine',
            'activity_type' => ReservationActivityType::STANDARD,
            'distance_km' => 12.50,
            'estimated_duration_min' => 25,
            'status' => ReservationStatus::CONFIRME,
            'approval_type' => ApprovalType::MANUEL,
            'validated_by_user_id' => $user->id,
            'validated_at' => '2026-02-20 10:15:00',
        ]);

        $secondReservation = Reservation::create([
            'requested_by_user_id' => $user->id,
            'organization_id' => $organization->id,
            'pickup_location_id' => $pickupLocation->id,
            'destination_location_id' => $destinationLocation->id,
            'trip_date' => '2026-03-03',
            'departure_time' => '13:30:00',
            'return_time' => '15:00:00',
            'nb_children' => 8,
            'nb_adults' => 1,
            'trip_purpose' => 'Sortie musée',
            'activity_type' => ReservationActivityType::STANDARD,
            'distance_km' => 10.00,
            'estimated_duration_min' => 20,
            'status' => ReservationStatus::CONFIRME,
            'approval_type' => ApprovalType::MANUEL,
            'validated_by_user_id' => $user->id,
            'validated_at' => '2026-02-20 10:15:00',
        ]);

        $service = app(RouteSheetDocumentService::class);

        $document = $service->generateForPeriod('2026-03-02', '2026-03-06');

        $document->refresh();

        $this->assertDatabaseHas('documents', [
            'id' => $document->id,
            'reservation_id' => null,
            'document_type' => DocumentType::FEUILLE_ROUTE->value,
            'file_name' => 'route-sheet-2026-03-02-2026-03-06.pdf',
            'mime_type' => 'application/pdf',
            'generation_mode' => 'auto',
            'template_key' => 'route_sheets.weekly',
            'template_version' => '1.0',
            'is_official' => false,
            'version' => 1,
            'is_available' => true,
        ]);

        Storage::disk('local')->assertExists($document->file_path);

        $pdfContent = Storage::disk('local')->get($document->file_path);

        $this->assertStringStartsWith('%PDF', $pdfContent);
        $this->assertSame(strlen($pdfContent), $document->file_size);
        $this->assertSame(hash('sha256', $pdfContent), $document->checksum);

        $this->assertSame(DocumentType::FEUILLE_ROUTE, $document->document_type);
        $this->assertNull($document->reservation_id);

        $context = $document->generation_context;

        $this->assertSame(DocumentType::FEUILLE_ROUTE->value, $context['document']['type']);
        $this->assertSame('du 02/03/2026 au 06/03/2026', $context['period']['label']);
        $this->assertCount(2, $context['rows']);

        $this->assertSame($firstReservation->id, $context['rows'][0]['reservation_id']);
        $this->assertSame($secondReservation->id, $context['rows'][1]['reservation_id']);

        $reservations = $document->routeSheetReservations()->get();

        $this->assertCount(2, $reservations);

        $this->assertSame($firstReservation->id, $reservations[0]->id);
        $this->assertSame(1, $reservations[0]->pivot->position);

        $this->assertSame($secondReservation->id, $reservations[1]->id);
        $this->assertSame(2, $reservations[1]->pivot->position);

        $this->assertDatabaseHas('document_reservation', [
            'document_id' => $document->id,
            'reservation_id' => $firstReservation->id,
            'position' => 1,
        ]);

        $this->assertDatabaseHas('document_reservation', [
            'document_id' => $document->id,
            'reservation_id' => $secondReservation->id,
            'position' => 2,
        ]);
    }
}
