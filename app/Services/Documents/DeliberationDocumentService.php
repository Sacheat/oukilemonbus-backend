<?php

namespace App\Services\Documents;

use App\Enums\DocumentType;
use App\Models\CouncilDecision;
use App\Models\Document;
use App\Services\Documents\ContextBuilders\DeliberationContextBuilder;
use App\Services\Documents\Generators\DeliberationGenerator;
use InvalidArgumentException;

/**
 * Service responsable de créer le document officiel de délibération
 * lié à une décision du Collège communal.
 *
 * Cette classe orchestre plusieurs responsabilités déjà séparées :
 * - DeliberationContextBuilder prépare les données métier ;
 * - DeliberationGenerator génère le PDF ;
 * - DocumentService stocke le fichier et crée l'enregistrement Document.
 *
 * Elle ne modifie pas directement CouncilDecisionService.
 */
class DeliberationDocumentService
{
    private const DIRECTORY_PREFIX = 'documents/council-decisions';

    public function __construct(
        private readonly DeliberationContextBuilder $contextBuilder,
        private readonly DeliberationGenerator $generator,
        private readonly DocumentService $documentService
    ) {
    }

    public function generateFor(CouncilDecision $councilDecision): Document
    {
        if (! $this->canGenerateFor($councilDecision)) {
            throw new InvalidArgumentException(
                'La délibération ne peut être générée que pour une décision approuvée.'
            );
        }

        $context = $this->contextBuilder->build($councilDecision);

        $pdfContent = $this->generator->generatePdf($context);

        $fileName = $this->generator->defaultPdfFileName($context);

        return $this->documentService->registerGeneratedDocument(
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
                'version' => $this->nextVersionFor($councilDecision),
                'is_available' => true,
            ]
        );
    }

    public function canGenerateFor(CouncilDecision $councilDecision): bool
    {
        return $this->decisionStatusValue($councilDecision) === 'approuve';
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
}
