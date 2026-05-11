<?php

namespace Tests\Unit;

use App\Enums\DocumentType;
use App\Models\CouncilDecision;
use App\Models\Document;
use App\Services\Documents\ContextBuilders\DeliberationContextBuilder;
use App\Services\Documents\DeliberationDocumentService;
use App\Services\Documents\DocumentService;
use App\Services\Documents\Generators\DeliberationGenerator;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Tests\TestCase;

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
            'file_name' => 'deliberation-coll-2026-001-42.pdf',
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
                'deliberation-coll-2026-001-42.pdf',
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
}
