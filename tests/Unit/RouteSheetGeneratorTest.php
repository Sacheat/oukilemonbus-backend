<?php

namespace Tests\Unit;

use App\Services\Documents\Generators\RouteSheetGenerator;
use Tests\TestCase;
use App\Enums\DocumentType;

class RouteSheetGeneratorTest extends TestCase
{
    public function test_it_generates_route_sheet_html_from_context(): void
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
            'rows' => [
                [
                    'reservation_id' => 42,
                    'date' => '03/03/2026',
                    'day_label' => 'Mardi',
                    'beneficiary' => [
                        'name' => 'Ecole communale de Dinant',
                        'contact_name' => 'Mme Martin',
                    ],
                    'passengers' => [
                        'planned_children' => 10,
                        'planned_adults' => 2,
                        'planned_total' => 12,
                        'actual_total' => null,
                    ],
                    'pickup' => [
                        'time' => '08h30',
                        'address' => 'Ecole communale de Dinant, 5500 Dinant',
                    ],
                    'destination' => [
                        'label' => 'Centre culturel de Dinant, 5500 Dinant',
                        'address' => 'Centre culturel de Dinant, 5500 Dinant',
                        'latitude' => '50.26300000',
                        'longitude' => '4.91400000',
                        'navigation_url' => 'https://www.google.com/maps/dir/?api=1&destination=50.26300000%2C4.91400000',
                    ],
                    'return_pickup_time' => '11h00',
                    'validation' => [
                        'date' => '20/02/2026',
                        'source' => 'reservation_validation',
                    ],
                ],
            ],
        ];

        $generator = new RouteSheetGenerator();

        $html = $generator->generateHtml($context);

        $this->assertStringContainsString('Feuille de route', $html);
        $this->assertStringContainsString('du 02/03/2026 au 06/03/2026', $html);
        $this->assertStringContainsString('Ecole communale de Dinant', $html);
        $this->assertStringContainsString('Mme Martin', $html);
        $this->assertStringContainsString('12', $html);
        $this->assertStringContainsString('08h30', $html);
        $this->assertStringContainsString('Centre culturel de Dinant, 5500 Dinant', $html);
        $this->assertStringContainsString('11h00', $html);
        $this->assertStringContainsString('20/02/2026', $html);
        $this->assertStringNotContainsString('file_path', $html);
    }

    public function test_it_generates_route_sheet_pdf_from_context(): void
    {
        $context = [
            'period' => [
                'start_date' => '2026-03-02',
                'end_date' => '2026-03-06',
                'label' => 'du 02/03/2026 au 06/03/2026',
            ],
            'rows' => [],
        ];

        $generator = new RouteSheetGenerator();

        $pdfContent = $generator->generatePdf($context);

        $this->assertNotEmpty($pdfContent);
        $this->assertStringStartsWith('%PDF', $pdfContent);
    }

    public function test_it_generates_a_safe_default_pdf_file_name(): void
    {
        $generator = new RouteSheetGenerator();

        $fileName = $generator->defaultPdfFileName([
            'period' => [
                'start_date' => '2026-03-02',
                'end_date' => '2026-03-06',
            ],
        ]);

        $this->assertSame('route-sheet-2026-03-02-2026-03-06.pdf', $fileName);
    }

    public function test_it_generates_a_safe_default_file_name(): void
    {
        $generator = new RouteSheetGenerator();

        $fileName = $generator->defaultFileName([
            'period' => [
                'start_date' => '2026-03-02',
                'end_date' => '2026-03-06',
            ],
        ]);

        $this->assertSame('route-sheet-2026-03-02-2026-03-06.html', $fileName);
    }
}
