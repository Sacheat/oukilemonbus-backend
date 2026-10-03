<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\RequestStatus;
use App\Models\OrgRegistrationRequest;
use App\Models\UserOrgRequest;
use App\Services\OrgRegistrationRequests\OrgRegistrationRequestService;
use App\Services\UserOrgRequests\UserOrgRequestService;
use Illuminate\Console\Command;

/**
 * Remet à jour la note de confiance enregistrée des demandes EN_ATTENTE (adhésions et nouvelles organisations).
 *
 * La note enregistrée trie les files de demandes. Les services la rafraîchissent à la création, quand le justificatif
 * change et à la décision ; cette commande rattrape le reste : demandes antérieures à ces règles et changements indirects
 * (téléphone ou compte du demandeur, autre demande en attente, organisation créée avec le même email). Les demandes
 * traitées gardent la note de leur décision. Planifiée chaque heure (routes/console.php) ; à lancer une fois après un déploiement.
 */
class RefreshRequestConfidenceNotesCommand extends Command
{
    protected $signature = 'requests:refresh-confidence-notes';

    protected $description = 'Recalcule la note de confiance enregistrée des demandes en attente (tri des files de demandes)';

    public function handle(
        UserOrgRequestService $userOrgRequests,
        OrgRegistrationRequestService $orgRegistrationRequests,
    ): int {
        $updated = 0;
        $total = 0;

        UserOrgRequest::query()
            ->where('status', RequestStatus::EN_ATTENTE)
            ->chunkById(100, function ($requests) use ($userOrgRequests, &$updated, &$total): void {
                foreach ($requests as $request) {
                    $total++;
                    $updated += (int) $userOrgRequests->refreshConfidenceNote($request);
                }
            }, 'request_id');

        OrgRegistrationRequest::query()
            ->where('status', RequestStatus::EN_ATTENTE)
            ->chunkById(100, function ($requests) use ($orgRegistrationRequests, &$updated, &$total): void {
                foreach ($requests as $request) {
                    $total++;
                    $updated += (int) $orgRegistrationRequests->refreshConfidenceNote($request);
                }
            });

        $this->info("{$total} demande(s) en attente vérifiée(s), {$updated} note(s) mise(s) à jour.");

        return self::SUCCESS;
    }
}
