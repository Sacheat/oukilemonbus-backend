<?php

namespace App\Services\Documents;

use App\Enums\DocumentType;
use App\Models\Document;
use App\Services\Documents\ContextBuilders\RouteSheetContextBuilder;
use App\Services\Documents\Generators\RouteSheetGenerator;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

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
        [$startDate, $endDate] = $this->normalizePeriod($startDate, $endDate);

        $context = $this->contextBuilder->buildForPeriod($startDate, $endDate);

        $pdfContent = $this->generator->generatePdf($context);

        $baseFileName = $this->generator->defaultPdfFileName($context);

        $version = $this->nextVersionForPeriod($startDate, $endDate);

        $fileName = $this->versionedFileName($baseFileName, $version);

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
                'version' => $version,
                'is_available' => true,
            ]
        );

        $this->attachRouteSheetReservations($document, $context);

        return $document;
    }

    public function generateForServiceDateVehicle(string $serviceDate, int $vehicleId): Document
    {
        $serviceDate = $this->normalizeDate($serviceDate);

        $context = $this->contextBuilder->buildForServiceDateVehicle($serviceDate, $vehicleId);

        $pdfContent = $this->generator->generatePdf($context);

        $baseFileName = $this->generator->defaultPdfFileName($context);

        $version = $this->nextVersionForServiceDateVehicle($serviceDate, $vehicleId);

        $fileName = $this->versionedFileName($baseFileName, $version);

        $document = $this->documentService->registerGeneratedDocument(
            documentType: DocumentType::FEUILLE_ROUTE,
            directory: $this->directoryForServiceDateVehicle($serviceDate, $vehicleId),
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
                'version' => $version,
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

    private function directoryForServiceDateVehicle(string $serviceDate, int $vehicleId): string
    {
        return self::DIRECTORY_PREFIX.'/'.$serviceDate.'/vehicle-'.$vehicleId;
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

    private function nextVersionForPeriod(string $startDate, string $endDate): int
    {
        $maxVersion = Document::query()
            ->where('document_type', DocumentType::FEUILLE_ROUTE->value)
            ->where('file_path', 'like', $this->directoryForPeriod($startDate, $endDate).'/%')
            ->max('version');

        return ((int) $maxVersion) + 1;
    }

    private function nextVersionForServiceDateVehicle(string $serviceDate, int $vehicleId): int
    {
        $maxVersion = Document::query()
            ->where('document_type', DocumentType::FEUILLE_ROUTE->value)
            ->where('file_path', 'like', $this->directoryForServiceDateVehicle($serviceDate, $vehicleId).'/%')
            ->max('version');

        return ((int) $maxVersion) + 1;
    }

    private function versionedFileName(string $fileName, int $version): string
    {
        $baseName = pathinfo($fileName, PATHINFO_FILENAME);
        $extension = pathinfo($fileName, PATHINFO_EXTENSION);

        if ($extension === '') {
            return sprintf('%s-v%03d', $baseName, $version);
        }

        return sprintf('%s-v%03d.%s', $baseName, $version, $extension);
    }

    private function normalizePeriod(string $startDate, string $endDate): array
    {
        try {
            $start = CarbonImmutable::parse($startDate)->startOfDay();
            $end = CarbonImmutable::parse($endDate)->startOfDay();
        } catch (\Throwable) {
            throw new InvalidArgumentException(
                'Les dates doivent etre valides.'
            );
        }

        if ($end->lessThan($start)) {
            throw new InvalidArgumentException(
                'La date de fin doit etre egale ou posterieure a la date de debut.'
            );
        }

        return [
            $start->toDateString(),
            $end->toDateString(),
        ];
    }

    private function normalizeDate(string $date): string
    {
        try {
            return CarbonImmutable::parse($date)->startOfDay()->toDateString();
        } catch (\Throwable) {
            throw new InvalidArgumentException(
                'La date de service doit etre valide.'
            );
        }
    }
}
