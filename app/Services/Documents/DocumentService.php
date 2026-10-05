<?php

namespace App\Services\Documents;

use App\Enums\DocumentType;
use App\Models\Document;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
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

    /**
     * Retourne une page de documents visibles par un utilisateur donné (règles d'accès de DocumentAccessService),
     * triés par date de génération puis de création décroissantes.
     *
     * Filtres : document_type[], exclude_type[], date_from / date_to (jour à Bruxelles, sur generated_at sinon
     * created_at), q (nom de fichier, numéro exact de réservation, objet du trajet), reservation_id, per_page (15 par défaut).
     * Les feuilles de route n'ont pas de reservation_id : elles sont retrouvées par la table pivot document_reservation.
     *
     * @param User $user L'utilisateur pour lequel on souhaite récupérer les documents visibles
     * @param array<string, mixed> $filters Filtres validés (ListDocumentsRequest)
     */
    public function listVisibleForUser(User $user, array $filters = []): LengthAwarePaginator
    {
        $timezone = config('reservations.local_timezone', 'Europe/Brussels');
        $day = fn (string $date): CarbonImmutable => CarbonImmutable::parse($date, $timezone);
        $reservationMatches = fn (Builder $query, callable $constraint) => $query
            ->whereHas('reservation', $constraint)
            ->orWhereHas('routeSheetReservations', $constraint);

        return $this->documentAccessService
            ->visibleQueryFor($user)
            ->when($filters['document_type'] ?? null, fn (Builder $query, array $types) => $query->whereIn('document_type', $types))
            ->when($filters['exclude_type'] ?? null, fn (Builder $query, array $types) => $query->whereNotIn('document_type', $types))
            ->when($filters['date_from'] ?? null, fn (Builder $query, string $date) => $query->whereRaw(
                'COALESCE(documents.generated_at, documents.created_at) >= ?',
                [$day($date)->startOfDay()->utc()->format('Y-m-d H:i:s')]
            ))
            ->when($filters['date_to'] ?? null, fn (Builder $query, string $date) => $query->whereRaw(
                'COALESCE(documents.generated_at, documents.created_at) <= ?',
                [$day($date)->endOfDay()->utc()->format('Y-m-d H:i:s')]
            ))
            ->when($filters['reservation_id'] ?? null, fn (Builder $query, int $reservationId) => $query->where(
                fn (Builder $query) => $reservationMatches($query, fn (Builder $reservation) => $reservation->whereKey($reservationId))
            ))
            ->when($filters['q'] ?? null, function (Builder $query, string $term): void {
                $term = trim($term);
                $like = '%'.addcslashes($term, '\\%_').'%';

                $query->where(function (Builder $query) use ($term, $like): void {
                    $query->where('documents.file_name', 'like', $like)
                        ->orWhereHas('reservation', fn (Builder $reservation) => $this->matchReservation($reservation, $term, $like))
                        ->orWhereHas('routeSheetReservations', fn (Builder $reservation) => $this->matchReservation($reservation, $term, $like));
                });
            })
            ->with(['reservation', 'user'])
            ->latest('generated_at')
            ->latest('created_at')
            ->latest('id')
            ->paginate((int) ($filters['per_page'] ?? 15));
    }

    /** Numéro exact de réservation (si le terme est un entier) ou objet du trajet contenant le terme. */
    private function matchReservation(Builder $reservation, string $term, string $like): void
    {
        $reservation->where(function (Builder $reservation) use ($term, $like): void {
            if (ctype_digit($term)) {
                $reservation->orWhere('reservations.id', (int) $term);
            }

            $reservation->orWhere('reservations.trip_purpose', 'like', $like);
        });
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
                    'service_date' => $data['service_date'] ?? null,
                    'vehicle_id' => $data['vehicle_id'] ?? null,
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

    /**
     * @param bool $inline Ouvre les PDF dans le navigateur (Content-Disposition: inline) au lieu de les télécharger.
     */
    public function download(Document $document, bool $inline = false): StreamedResponse
    {
        if (! $document->is_available) {
            abort(404, 'Document indisponible.');
        }

        if (! $this->storageService->exists($document->file_path)) {
            abort(404, 'Fichier introuvable.');
        }

        return $this->storageService->download($document, $inline);
    }

    public function markUnavailable(Document $document): Document
    {
        $document->update([
            'is_available' => false,
        ]);

        return $document->refresh();
    }
}
