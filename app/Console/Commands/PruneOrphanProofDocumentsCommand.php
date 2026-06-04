<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\DocumentType;
use App\Models\Document;
use App\Services\Documents\ProofDocumentService;
use Illuminate\Console\Command;

class PruneOrphanProofDocumentsCommand extends Command
{
    protected $signature = 'documents:prune-orphan-proofs
                            {--days=7 : Supprimer les orphelins plus anciens que N jours}
                            {--dry-run : Afficher sans supprimer}';

    protected $description = 'Supprime les justificatifs (JUSTIFICATIF) non liés à aucune demande ou organisation';

    public function handle(ProofDocumentService $proofDocumentService): int
    {
        $days   = (int) $this->option('days');
        $dryRun = (bool) $this->option('dry-run');

        $orphans = Document::query()
            ->where('document_type', DocumentType::JUSTIFICATIF->value)
            ->where('is_available', true)
            ->where('created_at', '<', now()->subDays($days))
            ->whereNotIn('id', fn ($q) => $q
                ->selectRaw('proof_document_id')
                ->from('org_registration_requests')
                ->whereNotNull('proof_document_id')
            )
            ->whereNotIn('id', fn ($q) => $q
                ->selectRaw('proof_document_id')
                ->from('user_org_requests')
                ->whereNotNull('proof_document_id')
            )
            ->whereNotIn('id', fn ($q) => $q
                ->selectRaw('proof_document_id')
                ->from('organizations')
                ->whereNotNull('proof_document_id')
            )
            ->get();

        if ($orphans->isEmpty()) {
            $this->info('Aucun justificatif orphelin trouvé.');
            return self::SUCCESS;
        }

        $this->info("{$orphans->count()} justificatif(s) orphelin(s) trouvé(s)" . ($dryRun ? ' (dry-run)' : '') . '.');

        foreach ($orphans as $doc) {
            $this->line("  → #{$doc->id} {$doc->file_name} ({$doc->created_at->toDateString()})");

            if (! $dryRun) {
                $proofDocumentService->delete($doc);
            }
        }

        if (! $dryRun) {
            $this->info('Suppression terminée.');
        }

        return self::SUCCESS;
    }
}