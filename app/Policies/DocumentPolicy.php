<?php

namespace App\Policies;

use App\Models\Document;
use App\Models\User;
use App\Services\Documents\DocumentAccessService;

/**
 * Policy de contrôle d'accès aux documents générés
 * - Applique les règles d'accès définies dans DocumentAccessService pour déterminer si un utilisateur donné peut voir, télécharger, générer ou mettre à jour un document donné
 * - Utilisée par les contrôleurs et les services pour sécuriser l'accès aux documents générés
 */
class DocumentPolicy
{
    public function __construct(
        private DocumentAccessService $documentAccessService
    ) {
    }

    public function viewAny(User $user): bool
    {
        return $this->documentAccessService->canViewAny($user);
    }

    public function view(User $user, Document $document): bool
    {
        return $this->documentAccessService->canView($user, $document);
    }

    public function download(User $user, Document $document): bool
    {
        return $this->documentAccessService->canDownload($user, $document);
    }

    public function generate(User $user): bool
    {
        return $this->documentAccessService->canGenerate($user);
    }

    public function update(User $user, Document $document): bool
    {
        return $this->documentAccessService->canUpdate($user, $document);
    }
}
