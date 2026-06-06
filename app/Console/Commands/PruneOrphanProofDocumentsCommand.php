<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\DocumentType;
use App\Models\Document;
use App\Services\Documents\ProofDocumentService;
use Illuminate\Console\Command;

/**
 * Supprime les justificatifs uploadés mais jamais rattachés à une demande ou une organisation.
 * Situation typique : un utilisateur a uploadé un fichier puis abandonné le formulaire.
 * Le délai de grâce (--days) évite de supprimer un justificatif d'une transaction en cours.
 */
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

        // Un justificatif est orphelin s'il n'est référencé dans aucune des trois tables
        // pouvant porter un proof_document_id : demandes d'inscription, demandes d'adhésion, organisations.
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
                // ProofDocumentService supprime le fichier physique ET l'entrée en base.
                $proofDocumentService->delete($doc);
            }
        }

        if (! $dryRun) {
            $this->info('Suppression terminée.');
        }

        return self::SUCCESS;
    }
}