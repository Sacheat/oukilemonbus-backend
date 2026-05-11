<?php

namespace Tests\Unit;

use App\Services\Documents\Generators\DeliberationGenerator;
use Barryvdh\DomPDF\Facade\Pdf;
use Tests\TestCase;

class DeliberationGeneratorTest extends TestCase
{
    public function test_it_generates_deliberation_html_from_context(): void
    {
        $context = [
            'document' => [
                'type' => 'deliberation',
                'template_key' => 'deliberations.external',
                'template_version' => '1.0',
            ],
            'decision' => [
                'date' => '2026-04-20',
                'reference' => 'COLL-2026-001',
            ],
            'reservation' => [
                'id' => 42,
                'trip_date' => '2026-06-16',
                'departure_time' => '09:00',
                'return_time' => '11:00',
                'trip_purpose' => 'Sortie éducative',
            ],
            'organization' => [
                'name' => 'ASBL Solidarité Dinantaise',
                'type' => 'asbl',
                'type_label' => 'ASBL',
            ],
            'locations' => [
                'pickup' => [
                    'formatted_address' => 'Place Albert 1er, 5500 Dinant',
                ],
                'destination' => [
                    'formatted_address' => 'Rue de Namur 20, 5000 Namur',
                ],
            ],
        ];

        $generator = new DeliberationGenerator;

        $html = $generator->generateHtml($context);

        $this->assertStringContainsString('Extrait du registre aux délibérations', $html);
        $this->assertStringContainsString('du Collège communal de Dinant', $html);
        $this->assertStringContainsString('ASBL Solidarité Dinantaise', $html);
        $this->assertStringContainsString('2026-06-16', $html);
        $this->assertStringContainsString('Place Albert 1er, 5500 Dinant', $html);
        $this->assertStringContainsString('Rue de Namur 20, 5000 Namur', $html);
        $this->assertStringContainsString('COLL-2026-001', $html);
        $this->assertStringNotContainsString('file_path', $html);
    }

    public function test_it_generates_deliberation_pdf_from_context(): void
    {
        $context = [
            'decision' => [
                'date' => '2026-04-20',
                'reference' => 'COLL-2026-001',
            ],
            'reservation' => [
                'id' => 42,
                'trip_date' => '2026-06-16',
                'departure_time' => '09:00',
                'return_time' => '11:00',
                'trip_purpose' => 'Sortie éducative',
            ],
            'organization' => [
                'name' => 'ASBL Solidarité Dinantaise',
                'type' => 'asbl',
                'type_label' => 'ASBL',
            ],
            'locations' => [
                'pickup' => [
                    'formatted_address' => 'Place Albert 1er, 5500 Dinant',
                ],
                'destination' => [
                    'formatted_address' => 'Rue de Namur 20, 5000 Namur',
                ],
            ],
        ];

        $generator = new DeliberationGenerator;

        $pdfContent = $generator->generatePdf($context);

        $this->assertNotEmpty($pdfContent);
        $this->assertStringStartsWith('%PDF', $pdfContent);
    }

    public function test_it_generates_a_safe_default_pdf_file_name(): void
    {
        $generator = new DeliberationGenerator;

        $fileName = $generator->defaultPdfFileName([
            'decision' => [
                'reference' => 'COLL 2026/001',
            ],
            'reservation' => [
                'id' => 42,
            ],
        ]);

        $this->assertSame('deliberation-coll-2026-001-42.pdf', $fileName);
    }

    public function test_it_generates_a_safe_default_file_name(): void
    {
        $generator = new DeliberationGenerator;

        $fileName = $generator->defaultFileName([
            'decision' => [
                'reference' => 'COLL 2026/001',
            ],
            'reservation' => [
                'id' => 42,
            ],
        ]);

        $this->assertSame('deliberation-coll-2026-001-42.html', $fileName);
    }

}
