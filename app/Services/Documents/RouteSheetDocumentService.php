<?php

namespace App\Services\Documents;

use App\Enums\DocumentType;
use App\Models\Document;
use App\Services\Documents\ContextBuilders\RouteSheetContextBuilder;
use App\Services\Documents\Generators\RouteSheetGenerator;

class RouteSheetDocumentService
{
    private const DIRECTORY_PREFIX = 'documents/route-sheets';

    public function __construct(
        private readonly RouteSheetContextBuilder $contextBuilder,
        private readonly RouteSheetGenerator $generator,
        private readonly DocumentService $documentService
    ) {
    }

    public function generateForPeriod(string $startDate, string $endDate): Document
    {
        $context = $this->contextBuilder->buildForPeriod($startDate, $endDate);

        $pdfContent = $this->generator->generatePdf($context);

        $fileName = $this->generator->defaultPdfFileName($context);

        $document = $this->documentService->registerGeneratedDocument(
            documentType: DocumentType::FEUILLE_ROUTE,
            directory: $this->directoryForPeriod($startDate, $endDate),
            fileName: $fileName,
            content: $pdfContent,
            data: [
                'reservation_id' => null,
                'user_id' => null,
                'generation_mode' => 'auto',
                'generation_context' => $context,
                'template_key' => data_get($context, 'document.template_key'),
                'template_version' => data_get($context, 'document.template_version'),
                'generated_at' => now(),
                'is_official' => false,
                'version' => 1,
                'is_available' => true,
            ]
        );

        $this->attachRouteSheetReservations($document, $context);

        return $document;
    }

    private function directoryForPeriod(string $startDate, string $endDate): string
    {
        return self::DIRECTORY_PREFIX.'/'.$startDate.'-'.$endDate;
    }

    private function attachRouteSheetReservations(Document $document, array $context): void
    {
        $syncData = [];

        foreach (data_get($context, 'rows', []) as $index => $row) {
            $reservationId = data_get($row, 'reservation_id');

            if ($reservationId === null) {
                continue;
            }

            $syncData[$reservationId] = [
                'position' => $index + 1,
            ];
        }

        $document->routeSheetReservations()->sync($syncData);
    }
}
