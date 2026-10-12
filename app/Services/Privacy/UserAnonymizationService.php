<?php

namespace App\Services\Privacy;

use App\Enums\DriverAlertStatus;
use App\Enums\RequestStatus;
use App\Enums\ReservationStatus;
use App\Models\ApiIdempotencyKey;
use App\Models\Document;
use App\Models\DriverAlert;
use App\Models\Municipality;
use App\Models\NotificationDelivery;
use App\Models\Organization;
use App\Models\OrgRegistrationRequest;
use App\Models\Reservation;
use App\Models\User;
use App\Models\UserOrgRequest;
use App\Services\Audit\DomainEventLogger;
use App\Services\Documents\ProofDocumentService;
use App\Services\Users\UserService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * RGPD, droit à l'effacement (art. 17) : un compte n'est jamais supprimé, il est anonymisé.
 *
 * Les réservations, historiques, comptes rendus et documents générés (feuilles de route, délibérations) restent :
 * ils relèvent d'obligations légales de conservation (art. 17.3.b) et pointent ensuite vers « Utilisateur supprimé ».
 * Tout ce qui identifie la personne ou ne sert qu'à son compte est vidé ou supprimé (voir anonymize()).
 *
 * Refusé (422) tant que la personne a des engagements en cours : réservations à venir, missions de chauffeur à venir,
 * demande d'aide ouverte, ou dernier administrateur actif. L'agent les règle d'abord (annulation, réaffectation).
 *
 * Les justificatifs des demandes sont supprimés après la transaction : un fichier effacé ne revient pas si la
 * transaction échoue. L'Inspector (Tooling) n'est pas touché : ses entrées sont purgées après sa propre rétention.
 */
class UserAnonymizationService
{
    public const string ANONYMIZED_FIRST_NAME = 'Utilisateur';

    private const string REJECTION_REASON = 'Compte supprimé à la demande de la personne (RGPD).';

    public function __construct(
        private readonly UserService $userService,
        private readonly ProofDocumentService $proofDocumentService,
        private readonly DomainEventLogger $eventLogger,
    ) {}

    /**
     * @throws ValidationException compte déjà anonymisé, ou engagements en cours (liste dans `user`)
     */
    public function anonymize(User $user, User $actor): User
    {
        $proofDocumentIds = DB::transaction(function () use ($user, $actor): array {
            $user = User::query()->lockForUpdate()->findOrFail($user->getKey());

            if ($user->isAnonymized()) {
                throw ValidationException::withMessages(['user' => 'Ce compte est déjà anonymisé.']);
            }

            $this->userService->ensureNotLastActiveAdmin($user, 'user', "Impossible d'anonymiser le dernier administrateur actif.");
            $this->ensureNoPendingCommitments($user);

            $proofDocumentIds = $this->closeRequests($user, $actor);

            $this->clearAccountData($user);
            $this->anonymizeDriverProfile($user);
            $this->anonymizeIdentity($user);

            $this->eventLogger->log('user', $user->getKey(), 'user.anonymized', $actor);

            return $proofDocumentIds;
        });

        $this->deleteProofDocuments($proofDocumentIds);

        return $user->refresh();
    }

    private function ensureNoPendingCommitments(User $user): void
    {
        $today = Carbon::now(config('reservations.local_timezone', 'Europe/Brussels'))->toDateString();
        $errors = [];

        $upcomingReservations = Reservation::query()
            ->where('requested_by_user_id', $user->getKey())
            ->whereDate('trip_date', '>=', $today)
            ->whereIn('status', ReservationStatus::activeValues())
            ->count();

        if ($upcomingReservations > 0) {
            $errors[] = "{$upcomingReservations} réservation(s) à venir demandée(s) par ce compte : annulez-les d'abord.";
        }

        if ($driver = $user->driver) {
            $upcomingMissions = Reservation::query()
                ->where('driver_id', $driver->getKey())
                ->whereDate('trip_date', '>=', $today)
                ->whereIn('status', ReservationStatus::activeValues())
                ->count();

            if ($upcomingMissions > 0) {
                $errors[] = "{$upcomingMissions} trajet(s) à venir affecté(s) à ce chauffeur : réaffectez-les d'abord.";
            }

            $openAlert = DriverAlert::query()
                ->where('driver_id', $driver->getKey())
                ->where('status', '!=', DriverAlertStatus::CLOTUREE->value)
                ->exists();

            if ($openAlert) {
                $errors[] = "Une demande d'aide de ce chauffeur est encore ouverte : clôturez-la d'abord.";
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages(['user' => $errors]);
        }
    }

    /**
     * Demandes en attente refusées, données de contact des demandes vidées, justificatifs détachés.
     *
     * @return list<int> justificatifs à supprimer après la transaction
     */
    private function closeRequests(User $user, User $actor): array
    {
        $refusal = [
            'status' => RequestStatus::REFUSE->value,
            'reviewed_by_user_id' => $actor->getKey(),
            'reviewed_at' => now(),
            'rejection_reason' => self::REJECTION_REASON,
        ];

        $userOrgRequests = UserOrgRequest::query()->where('requested_by_user_id', $user->getKey());
        $orgRegistrationRequests = OrgRegistrationRequest::query()->where('request_by_user_id', $user->getKey());

        $proofDocumentIds = array_merge(
            (clone $userOrgRequests)->whereNotNull('proof_document_id')->pluck('proof_document_id')->all(),
            (clone $orgRegistrationRequests)->whereNotNull('proof_document_id')->pluck('proof_document_id')->all(),
        );

        (clone $userOrgRequests)->where('status', RequestStatus::EN_ATTENTE->value)->update($refusal);
        (clone $orgRegistrationRequests)->where('status', RequestStatus::EN_ATTENTE->value)->update($refusal);

        $userOrgRequests->update(['justification' => null, 'proof_document_id' => null]);
        $orgRegistrationRequests->update([
            'contact_person_name' => null,
            'contact_person_role' => null,
            'phone' => null,
            'comment' => null,
            'proof_document_id' => null,
        ]);

        // Le justificatif d'une demande approuvée peut être devenu celui de l'organisation : il reste.
        $keptByOrganizations = Organization::query()->whereIn('proof_document_id', $proofDocumentIds)->pluck('proof_document_id')->all();

        return array_values(array_diff(array_unique($proofDocumentIds), $keptByOrganizations));
    }

    /** Accès, appartenances et traces qui ne servent qu'au compte. */
    private function clearAccountData(User $user): void
    {
        $this->userService->closeOtherSessions($user);

        $user->organizations()->detach();
        $user->syncRoles([]);
        $user->notificationPreference()->delete();
        $user->notificationDigestQueueItems()->delete();
        $user->notifications()->delete();

        ApiIdempotencyKey::query()->where('user_id', $user->getKey())->delete();

        NotificationDelivery::query()
            ->where('notifiable_type', $user->getMorphClass())
            ->where('notifiable_id', $user->getKey())
            ->update(['recipient_email' => null, 'payload' => null]);

        Municipality::query()
            ->where('weekly_planning_owner_user_id', $user->getKey())
            ->update(['weekly_planning_owner_user_id' => null]);
    }

    private function anonymizeDriverProfile(User $user): void
    {
        $user->driver?->update([
            'licence_number' => 'ANON-'.$user->driver->getKey(),
            'is_active' => false,
        ]);
    }

    private function anonymizeIdentity(User $user): void
    {
        $user->forceFill([
            'first_name' => self::ANONYMIZED_FIRST_NAME,
            'last_name' => 'supprimé #'.$user->getKey(),
            'email' => "deleted-{$user->getKey()}@anonymized.invalid",
            'phone' => null,
            'password' => Hash::make(Str::random(64)),
            'remember_token' => null,
            'email_verified_at' => null,
            'active' => false,
            'anonymized_at' => now(),
        ])->save();
    }

    /** @param list<int> $documentIds */
    private function deleteProofDocuments(array $documentIds): void
    {
        foreach (Document::query()->whereKey($documentIds)->get() as $document) {
            try {
                $this->proofDocumentService->delete($document);
            } catch (\Throwable $exception) {
                // Le compte est déjà anonymisé ; un fichier restant sera repris par documents:prune-orphan-proofs.
                report($exception);
            }
        }
    }
}
