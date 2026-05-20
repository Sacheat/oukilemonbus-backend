<?php

namespace App\Services\Documents;

use App\Enums\CouncilDecisionStatus;
use App\Enums\DocumentType;
use App\Models\CouncilDecision;
use App\Models\Document;
use App\Services\Documents\ContextBuilders\DeliberationContextBuilder;
use App\Services\Documents\Generators\DeliberationGenerator;
use App\Services\Logging\DomainLogger;
use InvalidArgumentException;
use Throwable;

class DeliberationDocumentService
{
    private const DIRECTORY_PREFIX = 'documents/council-decisions';

    private DomainLogger $logger;

    public function __construct(
        private readonly DeliberationContextBuilder $contextBuilder,
        private readonly DeliberationGenerator $generator,
        private readonly DocumentService $documentService,
        ?DomainLogger $logger = null
    ) {
        $this->logger = $logger ?? app(DomainLogger::class);
    }

    public function generateFor(CouncilDecision $councilDecision): Document
    {
        if (! $this->canGenerateFor($councilDecision)) {
            throw new InvalidArgumentException(
                'La deliberation ne peut etre generee que pour une decision approuvee ou refusee.'
            );
        }

        $logContext = [
            'council_decision_id' => $councilDecision->id,
            'reservation_id' => $councilDecision->reservation_id,
            'decision_status' => $this->decisionStatusValue($councilDecision),
        ];

        $this->logger->deliberationGenerationStarted($logContext);

        try {
            $context = $this->contextBuilder->build($councilDecision);

            $pdfContent = $this->generator->generatePdf($context);

            $version = $this->nextVersionFor($councilDecision);

            $fileName = $this->versionedFileName(
                $this->generator->defaultPdfFileName($context),
                $version
            );

            $document = $this->documentService->registerGeneratedDocument(
                documentType: DocumentType::DELIBERATION,
                directory: $this->directoryFor($councilDecision),
                fileName: $fileName,
                content: $pdfContent,
                data: [
                    'reservation_id' => $councilDecision->reservation_id,
                    'user_id' => $councilDecision->recorded_by_user_id,
                    'generation_mode' => 'auto',
                    'generation_context' => $context,
                    'template_key' => data_get($context, 'document.template_key'),
                    'template_version' => data_get($context, 'document.template_version'),
                    'generated_at' => now(),
                    'is_official' => true,
                    'version' => $version,
                    'is_available' => true,
                ]
            );

            $this->logger->deliberationGenerationSucceeded($document, $logContext);

            return $document;
        } catch (Throwable $exception) {
            $this->logger->deliberationGenerationFailed($logContext, $exception);

            throw $exception;
        }
    }

    public function canGenerateFor(CouncilDecision $councilDecision): bool
    {
        return in_array($this->decisionStatusValue($councilDecision), [
            CouncilDecisionStatus::APPROUVE->value,
            CouncilDecisionStatus::REFUSE->value,
        ], true);
    }

    private function directoryFor(CouncilDecision $councilDecision): string
    {
        return self::DIRECTORY_PREFIX.'/'.$councilDecision->getKey();
    }

    private function nextVersionFor(CouncilDecision $councilDecision): int
    {
        if ($councilDecision->reservation_id === null) {
            return 1;
        }

        $lastVersion = Document::query()
            ->where('reservation_id', $councilDecision->reservation_id)
            ->where('document_type', DocumentType::DELIBERATION->value)
            ->max('version');

        return ((int) $lastVersion) + 1;
    }

    private function decisionStatusValue(CouncilDecision $councilDecision): ?string
    {
        $status = $councilDecision->decision_status;

        return $status instanceof \BackedEnum
            ? $status->value
            : $status;
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
}
