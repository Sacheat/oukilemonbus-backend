<?php

namespace App\Services\Documents;

use App\Enums\DocumentType;
use App\Models\Document;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Service de gestion des documents générés
 * - Fournit une méthode pour lister les documents visibles par un utilisateur donné, en appliquant les règles d'accès définies dans DocumentAccessService
 * - Fournit une méthode pour enregistrer un nouveau document généré, en stockant le fichier via DocumentStorageService et en créant une entrée dans la base de données
 * - Fournit une méthode pour télécharger un document, en vérifiant sa disponibilité et son existence sur le disque avant de servir le fichier via DocumentStorageService
 * - Fournit une méthode pour marquer un document comme indisponible, afin de le retirer de la liste des documents visibles et téléchargeables sans supprimer l'entrée de la base de données ni le fichier stocké
 */
class DocumentService
{
    public function __construct(
        private readonly DocumentStorageService $storageService,
        private readonly DocumentAccessService $documentAccessService
    ) {
    }

    public function listVisibleForUser(User $user): Collection
    {
        return $this->documentAccessService
            ->visibleQueryFor($user)
            ->with(['reservation', 'user'])
            ->latest('generated_at')
            ->latest('created_at')
            ->get();
    }

    public function registerGeneratedDocument(DocumentType $documentType, string $directory, string $fileName, string $content, array $data = []): Document {

        $storedFilePath = null;

        /*
        * La création d'un document officiel implique deux opérations distinctes :
        * 1. stocker physiquement le fichier dans le stockage privé ;
        * 2. créer l'enregistrement Document correspondant en base de données.
        *
        * La transaction SQL protège uniquement la base de données.
        * Elle ne peut pas annuler automatiquement un fichier déjà écrit sur le disque.
        *
        * On mémorise donc le chemin du fichier stocké afin de pouvoir le supprimer
        * manuellement si la création de l'enregistrement Document échoue.
        */
        try {
            return DB::transaction(function () use ($documentType, $directory, $fileName, $content, $data, &$storedFilePath): Document {
                $storedFile = $this->storageService->storeGeneratedFile(
                    directory: $directory,
                    fileName: $fileName,
                    content: $content
                );

                $storedFilePath = $storedFile['file_path'];

                return Document::create([
                    'reservation_id' => $data['reservation_id'] ?? null,
                    'user_id' => $data['user_id'] ?? null,
                    'document_type' => $documentType,
                    'file_name' => $storedFile['file_name'],
                    'file_path' => $storedFile['file_path'],
                    'mime_type' => $storedFile['mime_type'],
                    'file_size' => $storedFile['file_size'],
                    'checksum' => $storedFile['checksum'],
                    'generation_mode' => $data['generation_mode'] ?? 'auto',
                    'generation_context' => $data['generation_context'] ?? null,
                    'template_key' => $data['template_key'] ?? null,
                    'template_version' => $data['template_version'] ?? null,
                    'generated_at' => $data['generated_at'] ?? now(),
                    'is_official' => $data['is_official'] ?? true,
                    'version' => $data['version'] ?? 1,
                    'is_available' => $data['is_available'] ?? true,
                ]);
            });
            /*
            * Si une erreur survient après le stockage du fichier, on supprime le fichier
            * déjà écrit afin d'éviter qu'il reste seul sur le disque sans document associé
            * en base de données.
            */
        } catch (Throwable $exception) {
            if ($storedFilePath !== null) {
                $this->storageService->delete($storedFilePath);
            }

            throw $exception;
        }
    }

    public function download(Document $document): StreamedResponse
    {
        if (! $document->is_available) {
            abort(404, 'Document indisponible.');
        }

        if (! $this->storageService->exists($document->file_path)) {
            abort(404, 'Fichier introuvable.');
        }

        return $this->storageService->download($document);
    }

    public function markUnavailable(Document $document): Document
    {
        $document->update([
            'is_available' => false,
        ]);

        return $document->refresh();
    }
}
