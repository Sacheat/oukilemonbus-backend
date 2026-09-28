<?php

namespace App\Services\Documents;

use App\Enums\DocumentType;
use App\Models\Document;
use App\Services\Documents\ContextBuilders\RouteSheetContextBuilder;
use App\Services\Documents\Generators\RouteSheetGenerator;
use App\Services\Logging\DomainLogger;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Throwable;

/**
 * Génère et enregistre les feuilles de route PDF.
 *
 * Deux modes de génération :
 *   - `generateForPeriod()` : toutes les réservations CONFIRME sur une plage de dates
 *     (template hebdomadaire).
 *   - `generateForServiceDateVehicle()` : réservations CONFIRME d'un véhicule à une date
 *     donnée (template journalier, régénéré après confirmPassengers() si c'est aujourd'hui).
 *
 * Chaque génération incrémente le numéro de version (max(version) + 1 pour le même répertoire)
 * et attache les réservations concernées via la table pivot route_sheet_reservations (avec position).
 * Le fichier physique est stocké sur le disque 'local' (privé) via DocumentStorageService.
 * En cas d'échec après stockage, le fichier orphelin est supprimé par DocumentService.
 */
class RouteSheetDocumentService
{
    private const DIRECTORY_PREFIX = 'documents/route-sheets';

    private DomainLogger $logger;

    public function __construct(
        private readonly RouteSheetContextBuilder $contextBuilder,
        private readonly RouteSheetGenerator $generator,
        private readonly DocumentService $documentService,
        ?DomainLogger $logger = null
    ) {
        $this->logger = $logger ?? app(DomainLogger::class);
    }

    public function generateForPeriod(string $startDate, string $endDate): Document
    {
        [$startDate, $endDate] = $this->normalizePeriod($startDate, $endDate);

        $logContext = [
            'mode' => 'period',
            'start_date' => $startDate,
            'end_date' => $endDate,
        ];

        $this->logger->routeSheetGenerationStarted($logContext);

        try {
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

            $this->logger->routeSheetGenerationSucceeded($document, [
                ...$logContext,
                'reservation_count' => count(data_get($context, 'rows', [])),
            ]);

            return $document;
        } catch (Throwable $exception) {
            $this->logger->routeSheetGenerationFailed($logContext, $exception);

            throw $exception;
        }
    }

    public function generateForServiceDateVehicle(string $serviceDate, int $vehicleId): Document
    {
        $serviceDate = $this->normalizeDate($serviceDate);

        $logContext = [
            'mode' => 'daily_vehicle',
            'service_date' => $serviceDate,
            'vehicle_id' => $vehicleId,
        ];

        $this->logger->routeSheetGenerationStarted($logContext);

        try {
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

            $this->logger->routeSheetGenerationSucceeded($document, [
                ...$logContext,
                'reservation_count' => count(data_get($context, 'rows', [])),
            ]);

            return $document;
        } catch (Throwable $exception) {
            $this->logger->routeSheetGenerationFailed($logContext, $exception);

            throw $exception;
        }
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
        } catch (Throwable) {
            throw new InvalidArgumentException(
                'Les dates doivent être valides.'
            );
        }

        if ($end->lessThan($start)) {
            throw new InvalidArgumentException(
                'La date de fin doit être égale ou postérieure à la date de début.'
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
        } catch (Throwable) {
            throw new InvalidArgumentException(
                'La date de service doit être valide.'
            );
        }
    }
}
