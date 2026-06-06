<?php

namespace App\Jobs;

use App\Enums\DocumentType;
use App\Enums\ReservationStatus;
use App\Models\Document;
use App\Models\Reservation;
use App\Services\Documents\RouteSheetDocumentService;
use App\Services\Logging\DomainLogger;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Génère les feuilles de route pour tous les véhicules affectés à une date de service donnée.
 * Implémente ShouldBeUnique avec une clé = serviceDate pour éviter les doublons si le scheduler
 * se déclenche plusieurs fois. `force = true` régénère même si le document existe déjà.
 * Backoff exponentiel : 60s → 300s → 900s. File `low` pour ne pas bloquer les workers critiques.
 * Retourne un tableau résumé {generated, skipped, failed} utilisé par GenerateDailyRouteSheetsCommand.
 */
class GenerateDailyRouteSheetsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 180;

    public bool $failOnTimeout = true;

    // Une seule instance du job par date de service peut exister dans la queue simultanément.
    public int $uniqueFor = 3600;

    public function __construct(
        public readonly string $serviceDate,
        public readonly bool $force = false
    ) {
        $this->onQueue((string) config('queue.names.low', 'low'));
    }

    public function uniqueId(): string
    {
        return $this->serviceDate;
    }

    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(RouteSheetDocumentService $routeSheetDocumentService, DomainLogger $logger): array
    {
        // Seules les réservations CONFIRME avec un véhicule affecté génèrent une feuille.
        $vehicleIds = Reservation::query()
            ->whereDate('trip_date', $this->serviceDate)
            ->where('status', ReservationStatus::CONFIRME->value)
            ->whereNotNull('vehicle_id')
            ->distinct()
            ->orderBy('vehicle_id')
            ->pluck('vehicle_id')
            ->map(fn (mixed $vehicleId): int => (int) $vehicleId)
            ->all();

        $summary = [
            'service_date' => $this->serviceDate,
            'generated' => [],
            'skipped' => [],
            'failed' => [],
        ];

        $logger->dailyRouteSheetsStarted([
            'service_date' => $this->serviceDate,
            'force' => $this->force,
            'vehicle_count' => count($vehicleIds),
            'vehicle_ids' => $vehicleIds,
        ]);

        foreach ($vehicleIds as $vehicleId) {
            if (! $this->force && $this->dailyRouteSheetExists($vehicleId)) {
                $summary['skipped'][] = [
                    'vehicle_id' => $vehicleId,
                    'reason' => 'already_exists',
                ];

                $logger->routeSheetSkipped([
                    'service_date' => $this->serviceDate,
                    'vehicle_id' => $vehicleId,
                    'reason' => 'already_exists',
                ]);

                continue;
            }

            try {
                $document = $routeSheetDocumentService->generateForServiceDateVehicle(
                    $this->serviceDate,
                    $vehicleId
                );

                $summary['generated'][] = [
                    'vehicle_id' => $vehicleId,
                    'document_id' => $document->id,
                    'file_path' => $document->file_path,
                ];
            } catch (Throwable $exception) {
                report($exception);

                $summary['failed'][] = [
                    'vehicle_id' => $vehicleId,
                    'message' => $exception->getMessage(),
                ];
            }
        }

        $logger->dailyRouteSheetsFinished([
            'service_date' => $this->serviceDate,
            'force' => $this->force,
            'generated_count' => count($summary['generated']),
            'skipped_count' => count($summary['skipped']),
            'failed_count' => count($summary['failed']),
        ]);

        return $summary;
    }

    // Vérifie par chemin de fichier pour être robuste aux rechargements de données.
    private function dailyRouteSheetExists(int $vehicleId): bool
    {
        return Document::query()
            ->where('document_type', DocumentType::FEUILLE_ROUTE->value)
            ->where('template_key', 'route_sheets.daily')
            ->where('is_available', true)
            ->where('file_path', 'like', sprintf(
                'documents/route-sheets/%s/vehicle-%d/%%',
                $this->serviceDate,
                $vehicleId
            ))
            ->exists();
    }

    public function failed(Throwable $exception): void
    {
        Log::error('jobs.generate_daily_route_sheets.failed', [
            'service_date' => $this->serviceDate,
            'force' => $this->force,
            'message' => $exception->getMessage(),
        ]);
    }
}
