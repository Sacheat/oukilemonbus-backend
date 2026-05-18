<?php

namespace Tests\Feature;

use App\Enums\DocumentType;
use App\Models\Document;
use App\Services\Documents\RouteSheetDocumentService;
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
            ->expectsOutput('Generation de la feuille de route du 2026-03-02 au 2026-03-06...')
            ->expectsOutput('Feuille de route generee avec succes. Document ID : '.$document->id)
            ->expectsOutput('Fichier : '.$document->file_path)
            ->assertExitCode(0);
    }

    public function test_it_returns_failure_when_period_is_invalid(): void
    {
        $this->artisan('route-sheets:generate', [
            'startDate' => '2026-03-06',
            'endDate' => '2026-03-02',
        ])
            ->expectsOutput('Generation de la feuille de route du 2026-03-06 au 2026-03-02...')
            ->expectsOutput('La date de fin doit etre egale ou posterieure a la date de debut.')
            ->assertExitCode(1);
    }

    public function test_it_returns_failure_when_date_format_is_invalid(): void
    {
        $this->artisan('route-sheets:generate', [
            'startDate' => 'not-a-date',
            'endDate' => '2026-03-02',
        ])
            ->expectsOutput('Generation de la feuille de route du not-a-date au 2026-03-02...')
            ->expectsOutput('Les dates doivent etre valides.')
            ->assertExitCode(1);
    }
}
