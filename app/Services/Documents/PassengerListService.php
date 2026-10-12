<?php

namespace App\Services\Documents;

use App\Enums\DocumentType;
use App\Models\Document;
use App\Models\Reservation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Liste des passagers d'un trajet (« carnet de bord » demandé par le SPF Mobilité) : un fichier joint à la réservation.
 *
 * Qui : le demandeur, un responsable de l'organisation, un agent ou un admin (ReservationPolicy::managePassengerList).
 * Quand :
 *   si la réservation est refusée ou annulée → plus rien à joindre
 *   sinon si c'est un agent ou un admin     → à tout moment (il peut archiver la liste réelle après le trajet)
 *   sinon                                   → jusqu'à l'heure de départ, ensuite la liste est figée
 * Une seule liste par réservation : la remplacer supprime l'ancienne (fichier compris) et monte la version.
 * Lecture : comme tout document de la réservation (DocumentAccessService : agent, admin, demandeur, membres de
 * l'organisation, chauffeur affecté), téléchargement par GET /documents/{id}/download.
 * Conservation : 3 ans après l'année du trajet (config uploads.passenger_lists.retention_years), puis purgeExpired().
 */
class PassengerListService
{
    public const FIELD = 'passenger_list';

    public function __construct(
        private readonly ProofDocumentService $uploadValidator,
        private readonly DocumentStorageService $storage,
    ) {}

    /** Liste actuelle, ou null */
    public function current(Reservation $reservation): ?Document
    {
        return Document::query()
            ->where('reservation_id', $reservation->id)
            ->where('document_type', DocumentType::LISTE_PASSAGERS->value)
            ->latest('id')
            ->first();
    }

    /**
     * Ce qu'un utilisateur peut faire maintenant : { can_replace, closes_at (ISO, null = sans limite), reason }.
     * `reason` explique pourquoi c'est fermé (affiché tel quel).
     */
    public function window(Reservation $reservation, User $user): array
    {
        if ($reservation->status->isTerminal()) {
            return ['can_replace' => false, 'closes_at' => null, 'reason' => 'La réservation est refusée ou annulée.'];
        }

        if ($user->hasAnyRole(['admin', 'agent_communal'])) {
            return ['can_replace' => true, 'closes_at' => null, 'reason' => null];
        }

        $departure = $reservation->departureAt();
        $open = $departure === null || CarbonImmutable::now()->lessThan($departure);

        return [
            'can_replace' => $open,
            'closes_at' => $departure?->toISOString(),
            'reason' => $open ? null : 'Le trajet est parti : la liste des passagers ne peut plus être modifiée. Contactez le service si elle doit être corrigée.',
        ];
    }

    public function replace(Reservation $reservation, UploadedFile $file, User $user): Document
    {
        $this->ensureOpen($reservation, $user);
        $this->uploadValidator->validateAs($file, 'passenger_lists', self::FIELD);

        $previous = $this->current($reservation);
        $version = ($previous?->version ?? 0) + 1;
        $extension = strtolower($file->getClientOriginalExtension());
        $storedFilePath = null;

        try {
            return DB::transaction(function () use ($reservation, $file, $user, $previous, $version, $extension, &$storedFilePath): Document {
                // Nom lisible au téléchargement ; le dossier par réservation et la version le rendent unique
                $stored = $this->storage->storeGeneratedFile(
                    "documents/passenger-lists/{$reservation->id}",
                    "liste-passagers-reservation-{$reservation->id}-v{$version}-".Str::lower(Str::random(6)).".{$extension}",
                    $file->get()
                );
                $storedFilePath = $stored['file_path'];

                $document = Document::create([
                    'reservation_id' => $reservation->id,
                    'user_id' => $user->id,
                    'document_type' => DocumentType::LISTE_PASSAGERS,
                    'file_name' => $stored['file_name'],
                    'file_path' => $stored['file_path'],
                    'mime_type' => $stored['mime_type'],
                    'file_size' => $stored['file_size'],
                    'checksum' => $stored['checksum'],
                    'generation_mode' => 'manuel',
                    'generated_at' => now(),
                    'is_official' => false,
                    'version' => $version,
                    'is_available' => true,
                ]);

                if ($previous !== null) {
                    $this->deleteDocument($previous);
                }

                return $document;
            });
        } catch (Throwable $exception) {
            if ($storedFilePath !== null) {
                $this->storage->delete($storedFilePath);
            }

            throw $exception;
        }
    }

    public function remove(Reservation $reservation, User $user): void
    {
        $this->ensureOpen($reservation, $user);

        $current = $this->current($reservation);
        if ($current === null) {
            throw ValidationException::withMessages([self::FIELD => 'Aucune liste des passagers à retirer.']);
        }

        DB::transaction(fn () => $this->deleteDocument($current));
    }

    /**
     * Supprime les listes dont la conservation est échue : trajet d'une année antérieure à (année en cours − N).
     * Ex. N = 3 : un trajet de mars 2027 est gardé jusqu'au 31/12/2030, supprimé dès le 01/01/2031.
     * Renvoie le nombre de listes supprimées.
     */
    public function purgeExpired(): int
    {
        $years = (int) config('uploads.passenger_lists.retention_years', 3);
        $firstKeptDay = CarbonImmutable::now(config('reservations.local_timezone', 'Europe/Brussels'))
            ->startOfYear()
            ->subYears($years)
            ->toDateString();

        $deleted = 0;
        Document::query()
            ->where('document_type', DocumentType::LISTE_PASSAGERS->value)
            ->whereHas('reservation', fn ($query) => $query->whereDate('trip_date', '<', $firstKeptDay))
            ->orderBy('id')
            ->chunkById(100, function ($documents) use (&$deleted): void {
                foreach ($documents as $document) {
                    DB::transaction(fn () => $this->deleteDocument($document));
                    $deleted++;
                }
            });

        return $deleted;
    }

    private function ensureOpen(Reservation $reservation, User $user): void
    {
        $window = $this->window($reservation, $user);

        if (! $window['can_replace']) {
            throw ValidationException::withMessages([self::FIELD => $window['reason']]);
        }
    }

    /** Fichier et enregistrement supprimés : une liste nominative ne doit pas traîner après son remplacement */
    private function deleteDocument(Document $document): void
    {
        $this->storage->delete($document->file_path);
        $document->delete();
    }
}
