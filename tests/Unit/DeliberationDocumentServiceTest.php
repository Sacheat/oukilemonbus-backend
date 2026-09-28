<?php

namespace Tests\Unit;

use App\Enums\DocumentType;
use App\Models\CouncilDecision;
use App\Models\Document;
use App\Models\Location;
use App\Models\Organization;
use App\Models\Reservation;
use App\Models\User;
use App\Services\Documents\ContextBuilders\DeliberationContextBuilder;
use App\Services\Documents\DeliberationDocumentService;
use App\Services\Documents\DocumentService;
use App\Services\Documents\Generators\DeliberationGenerator;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Tests\TestCase;
use InvalidArgumentException;

class DeliberationDocumentServiceTest extends TestCase
{
    use DatabaseTransactions;

    public function test_it_generates_and_registers_a_deliberation_document(): void
    {
        Carbon::setTestNow('2026-04-20 09:00:00');

        $councilDecision = new CouncilDecision();
        $councilDecision->forceFill([
            'id' => 12,
            'reservation_id' => 42,
            'recorded_by_user_id' => 7,
            'decision_reference' => 'COLL-2026-001',
            'decision_status' => 'approuve',
        ]);

        $context = [
            'document' => [
                'type' => 'deliberation',
                'template_key' => 'deliberations.external',
                'template_version' => '1.0',
            ],
            'decision' => [
                'reference' => 'COLL-2026-001',
            ],
            'reservation' => [
                'id' => 42,
            ],
        ];

        $pdfContent = '%PDF fake deliberation content';

        $createdDocument = new Document();
        $createdDocument->forceFill([
            'id' => 99,
            'document_type' => DocumentType::DELIBERATION,
            'file_name' => 'deliberation-coll-2026-001-42-v001.pdf',
        ]);

        $contextBuilder = $this->createMock(DeliberationContextBuilder::class);
        $generator = $this->createMock(DeliberationGenerator::class);
        $documentService = $this->createMock(DocumentService::class);

        $contextBuilder->expects($this->once())
            ->method('build')
            ->with($councilDecision)
            ->willReturn($context);

        $generator->expects($this->once())
            ->method('generatePdf')
            ->with($context)
            ->willReturn($pdfContent);

        $generator->expects($this->once())
            ->method('defaultPdfFileName')
            ->with($context)
            ->willReturn('deliberation-coll-2026-001-42.pdf');

        $documentService->expects($this->once())
            ->method('registerGeneratedDocument')
            ->with(
                DocumentType::DELIBERATION,
                'documents/council-decisions/12',
                'deliberation-coll-2026-001-42-v001.pdf',
                $pdfContent,
                $this->callback(function (array $data) use ($context): bool {
                    $this->assertSame(42, $data['reservation_id']);
                    $this->assertSame(7, $data['user_id']);
                    $this->assertSame('auto', $data['generation_mode']);
                    $this->assertSame($context, $data['generation_context']);
                    $this->assertSame('deliberations.external', $data['template_key']);
                    $this->assertSame('1.0', $data['template_version']);
                    $this->assertTrue($data['is_official']);
                    $this->assertSame(1, $data['version']);
                    $this->assertTrue($data['is_available']);

                    return true;
                })
            )
            ->willReturn($createdDocument);

        $service = new DeliberationDocumentService(
            contextBuilder: $contextBuilder,
            generator: $generator,
            documentService: $documentService
        );

        $document = $service->generateFor($councilDecision);

        $this->assertSame($createdDocument, $document);

        Carbon::setTestNow();
    }

    public function test_it_refuses_to_generate_document_when_decision_is_not_final(): void
    {
        $councilDecision = new CouncilDecision();
        $councilDecision->forceFill([
            'id' => 12,
            'reservation_id' => 42,
            'recorded_by_user_id' => 7,
            'decision_status' => 'en_attente',
        ]);

        $contextBuilder = $this->createMock(DeliberationContextBuilder::class);
        $generator = $this->createMock(DeliberationGenerator::class);
        $documentService = $this->createMock(DocumentService::class);

        $contextBuilder->expects($this->never())->method('build');
        $generator->expects($this->never())->method('generatePdf');
        $documentService->expects($this->never())->method('registerGeneratedDocument');

        $service = new DeliberationDocumentService(
            contextBuilder: $contextBuilder,
            generator: $generator,
            documentService: $documentService
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('La délibération ne peut être générée que pour une décision approuvée ou refusée.');

        $service->generateFor($councilDecision);
    }

    public function test_it_generates_a_deliberation_document_for_a_refused_decision(): void
    {
        $councilDecision = new CouncilDecision();
        $councilDecision->forceFill([
            'id' => 12,
            'reservation_id' => 42,
            'recorded_by_user_id' => 7,
            'decision_reference' => 'COLL-2026-001',
            'decision_status' => 'refuse',
        ]);

        $context = [
            'document' => [
                'template_key' => 'deliberations.external',
                'template_version' => '1.0',
            ],
            'decision' => [
                'status' => 'refuse',
                'reference' => 'COLL-2026-001',
            ],
            'reservation' => [
                'id' => 42,
            ],
        ];

        $createdDocument = new Document();
        $createdDocument->forceFill([
            'id' => 99,
            'document_type' => DocumentType::DELIBERATION,
            'file_name' => 'deliberation-coll-2026-001-42-v001.pdf',
        ]);

        $contextBuilder = $this->createMock(DeliberationContextBuilder::class);
        $generator = $this->createMock(DeliberationGenerator::class);
        $documentService = $this->createMock(DocumentService::class);

        $contextBuilder->method('build')->willReturn($context);
        $generator->method('generatePdf')->willReturn('%PDF fake refused deliberation content');
        $generator->method('defaultPdfFileName')->willReturn('deliberation-coll-2026-001-42.pdf');

        $documentService->expects($this->once())
            ->method('registerGeneratedDocument')
            ->with(
                DocumentType::DELIBERATION,
                'documents/council-decisions/12',
                'deliberation-coll-2026-001-42-v001.pdf',
                '%PDF fake refused deliberation content',
                $this->callback(function (array $data) use ($context): bool {
                    $this->assertSame($context, $data['generation_context']);
                    $this->assertSame(1, $data['version']);

                    return true;
                })
            )
            ->willReturn($createdDocument);

        $service = new DeliberationDocumentService(
            contextBuilder: $contextBuilder,
            generator: $generator,
            documentService: $documentService
        );

        $document = $service->generateFor($councilDecision);

        $this->assertSame($createdDocument, $document);
    }

    public function test_it_creates_a_new_file_name_version_for_an_existing_deliberation(): void
    {
        Carbon::setTestNow('2026-04-20 09:00:00');

        $user = User::factory()->create();

        $pickupLocation = Location::create([
            'formatted_address' => 'Place Albert 1er, 5500 Dinant',
            'street' => 'Place Albert 1er',
            'street_number' => '1',
            'postal_code' => '5500',
            'city' => 'Dinant',
            'country' => 'BE',
            'latitude' => 50.260000,
            'longitude' => 4.910000,
            'provider' => 'test',
            'is_verified' => true,
        ]);

        $destinationLocation = Location::create([
            'formatted_address' => 'Rue de Namur 20, 5000 Namur',
            'street' => 'Rue de Namur',
            'street_number' => '20',
            'postal_code' => '5000',
            'city' => 'Namur',
            'country' => 'BE',
            'latitude' => 50.460000,
            'longitude' => 4.860000,
            'provider' => 'test',
            'is_verified' => true,
        ]);

        $organization = Organization::create([
            'name' => 'ASBL Test',
            'type' => 'asbl',
            'official_email' => 'asbl@example.test',
            'phone_contact' => '081000000',
            'name_contact' => 'Contact',
            'location_id' => $pickupLocation->id,
            'is_priority' => false,
            'annual_quota' => 5,
            'reservations_used_this_year' => 0,
            'quota_reset_year' => now()->startOfYear(),
        ]);

        $reservation = Reservation::create([
            'requested_by_user_id' => $user->id,
            'organization_id' => $organization->id,
            'pickup_location_id' => $pickupLocation->id,
            'destination_location_id' => $destinationLocation->id,
            'trip_date' => '2026-06-16',
            'departure_time' => '09:00:00',
            'return_time' => '11:00:00',
            'nb_children' => 10,
            'nb_adults' => 2,
            'trip_purpose' => 'Sortie educative',
            'activity_type' => 'standard',
            'distance_km' => 12,
            'estimated_duration_min' => 30,
            'status' => 'en_attente',
            'approval_type' => 'manuel',
        ]);

        Document::query()->create([
            'reservation_id' => $reservation->id,
            'user_id' => $user->id,
            'document_type' => DocumentType::DELIBERATION,
            'file_name' => 'deliberation-coll-2026-001-42-v001.pdf',
            'file_path' => 'documents/council-decisions/12/deliberation-coll-2026-001-42-v001.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 123,
            'checksum' => hash('sha256', 'v1'),
            'generation_mode' => 'auto',
            'generation_context' => [],
            'template_key' => 'deliberations.external',
            'template_version' => '1.0',
            'generated_at' => now(),
            'is_official' => true,
            'version' => 1,
            'is_available' => true,
        ]);

        $councilDecision = new CouncilDecision();
        $councilDecision->forceFill([
            'id' => 12,
            'reservation_id' => $reservation->id,
            'recorded_by_user_id' => $user->id,
            'decision_reference' => 'COLL-2026-001',
            'decision_status' => 'approuve',
        ]);

        $context = [
            'document' => [
                'template_key' => 'deliberations.external',
                'template_version' => '1.0',
            ],
            'reservation' => [
                'id' => 42,
            ],
        ];

        $createdDocument = new Document();
        $createdDocument->forceFill([
            'id' => 100,
            'document_type' => DocumentType::DELIBERATION,
            'file_name' => 'deliberation-coll-2026-001-42-v002.pdf',
        ]);

        $contextBuilder = $this->createMock(DeliberationContextBuilder::class);
        $generator = $this->createMock(DeliberationGenerator::class);
        $documentService = $this->createMock(DocumentService::class);

        $contextBuilder->method('build')->willReturn($context);
        $generator->method('generatePdf')->willReturn('%PDF fake deliberation content v2');
        $generator->method('defaultPdfFileName')->willReturn('deliberation-coll-2026-001-42.pdf');

        $documentService->expects($this->once())
            ->method('registerGeneratedDocument')
            ->with(
                DocumentType::DELIBERATION,
                'documents/council-decisions/12',
                'deliberation-coll-2026-001-42-v002.pdf',
                '%PDF fake deliberation content v2',
                $this->callback(function (array $data) use ($context): bool {
                    $this->assertSame(2, $data['version']);
                    $this->assertSame($context, $data['generation_context']);

                    return true;
                })
            )
            ->willReturn($createdDocument);

        $service = new DeliberationDocumentService(
            contextBuilder: $contextBuilder,
            generator: $generator,
            documentService: $documentService
        );

        $document = $service->generateFor($councilDecision);

        $this->assertSame('deliberation-coll-2026-001-42-v002.pdf', $document->file_name);

        Carbon::setTestNow();
    }
}
