<?php

namespace App\Jobs;

use App\Enums\DocumentType;
use App\Models\PlanningExportRequest;
use App\Services\Documents\DocumentService;
use App\Services\Notifications\NotificationDispatchService;
use App\Services\Planning\PlanningExportService;
use App\Services\Planning\PlanningService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Génère l'export planning (XLS via HTML) pour une PlanningExportRequest.
 * Lancé après POST /api/planning/export qui renvoie 202 — le client poll GET /api/planning/export/{id}.
 * Une notification est envoyée au demandeur quand le document est prêt.
 * En cas d'échec, le statut passe à `failed` et l'exception est re-levée pour le retry Laravel.
 * Garde-fou : si le statut est déjà `processing` ou `succeeded`, le job s'arrête sans retraiter.
 */
class GeneratePlanningExportJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 180;

    public bool $failOnTimeout = true;

    public function __construct(
        public readonly int $planningExportRequestId
    ) {
        $this->onQueue((string) config('queue.names.low', 'low'));
    }

    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(
        PlanningService $planningService,
        PlanningExportService $planningExportService,
        DocumentService $documentService,
        NotificationDispatchService $notificationDispatchService
    ): void {
        $exportRequest = PlanningExportRequest::query()
            ->with('requester')
            ->find($this->planningExportRequestId);

        // Export supprimé ou demandeur inexistant → abandon silencieux.
        if ($exportRequest === null || $exportRequest->requester === null) {
            return;
        }

        // Évite le double-traitement si le job est rejoué par le worker.
        if (in_array($exportRequest->status, ['processing', 'succeeded'], true)) {
            return;
        }

        $exportRequest->update([
            'status' => 'processing',
            'started_at' => now(),
            'error_message' => null,
        ]);

        try {
            $items = $planningService->forPeriod(
                $exportRequest->requester,
                $exportRequest->start_date->toDateString(),
                $exportRequest->end_date->toDateString(),
                false,
                $exportRequest->statuses ?? [],
                $exportRequest->filters ?? []
            );

            $content = $planningExportService->htmlExportContent(
                $items,
                $exportRequest->start_date->toDateString(),
                $exportRequest->end_date->toDateString()
            );

            $fileName = sprintf(
                'planning-%s-%s-export-%d.xls',
                $exportRequest->start_date->toDateString(),
                $exportRequest->end_date->toDateString(),
                $exportRequest->id
            );

            $document = $documentService->registerGeneratedDocument(
                documentType: DocumentType::PLANNING,
                directory: sprintf('documents/planning/%s', now()->format('Y/m')),
                fileName: $fileName,
                content: $content,
                data: [
                    'user_id' => $exportRequest->requested_by_user_id,
                    'generation_mode' => 'async',
                    'generation_context' => [
                        'start_date' => $exportRequest->start_date->toDateString(),
                        'end_date' => $exportRequest->end_date->toDateString(),
                        'statuses' => $exportRequest->statuses ?? [],
                        'filters' => $exportRequest->filters ?? [],
                        'planning_export_request_id' => $exportRequest->id,
                    ],
                    'template_key' => 'planning.export',
                    'template_version' => '1.0',
                    'is_official' => false,
                ]
            );

            $exportRequest->update([
                'status' => 'succeeded',
                'document_id' => $document->id,
                'completed_at' => now(),
            ]);

            $notificationDispatchService->dispatch(
                users: collect([$exportRequest->requester]),
                eventKey: 'planning.export.ready',
                category: 'driver_planning',
                title: 'Export planning disponible',
                message: sprintf(
                    'Votre export planning du %s au %s est prêt.',
                    $exportRequest->start_date->format('d/m/Y'),
                    $exportRequest->end_date->format('d/m/Y')
                ),
                document: $document,
                priority: 'normal',
                urgent: false
            );
        } catch (Throwable $exception) {
            $exportRequest->update([
                'status' => 'failed',
                'failed_at' => now(),
                'error_message' => mb_substr($exception->getMessage(), 0, 1500),
            ]);

            throw $exception;
        }
    }
}
