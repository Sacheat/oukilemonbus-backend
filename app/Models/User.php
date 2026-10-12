<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Notifications\Auth\QueuedResetPassword;
use App\Notifications\Auth\QueuedVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['first_name', 'last_name', 'email', 'password', 'phone', 'active'])]
#[Hidden(['password', 'remember_token'])]
/**
 * Utilisateur de l'application.
 * Implémente MustVerifyEmail — l'email doit être vérifié avant d'accéder aux routes protégées.
 * `guard_name = 'web'` force Spatie Permission à utiliser le guard web même pour les requêtes API
 * (Sanctum s'appuie sur le guard web pour les sessions SPA cookie).
 */
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, Notifiable;

    /** Rôles globaux. Être responsable d'une organisation dépend de l'appartenance, pas d'un rôle. */
    public const array GLOBAL_ROLES = ['admin', 'agent_communal', 'chauffeur', 'user'];

    /** Tutoriels de première connexion, un par espace de la SPA (`tours_seen`). */
    public const array TOURS = ['usager', 'agent', 'chauffeur'];

    // Spatie Permission doit utiliser le guard web même dans un contexte API Sanctum SPA.
    protected string $guard_name = 'web';

    public function driver(): HasOne
    {
        return $this->hasOne(Driver::class);
    }

    public function notificationPreference(): HasOne
    {
        return $this->hasOne(UserNotificationPreference::class);
    }

    public function notificationDigestQueueItems(): HasMany
    {
        return $this->hasMany(NotificationDigestQueue::class);
    }

    public function organizations(): BelongsToMany
    {
        return $this->belongsToMany(Organization::class, 'user_organization')
            ->using(UserOrganization::class)
            ->withPivot(['relationship_type', 'is_default'])
            ->withTimestamps();
    }

    public function isMemberOf(Organization|int|null $organization): bool
    {
        $organizationId = $organization instanceof Organization ? $organization->getKey() : $organization;

        return $organizationId !== null
            && $this->organizations()->whereKey($organizationId)->exists();
    }

    /**
     * Responsable de l'organisation : relation de type responsable ou directeur (UserOrganization::MANAGER_TYPES).
     * Les droits de gestion sont portés par l'appartenance, pas par un rôle global.
     */
    public function managesOrganization(Organization|int|null $organization): bool
    {
        $organizationId = $organization instanceof Organization ? $organization->getKey() : $organization;

        return $organizationId !== null
            && $this->organizations()
                ->whereKey($organizationId)
                ->wherePivotIn('relationship_type', UserOrganization::MANAGER_TYPES)
                ->exists();
    }

    /** @return list<int> */
    public function managedOrganizationIds(): array
    {
        return $this->organizations()
            ->wherePivotIn('relationship_type', UserOrganization::MANAGER_TYPES)
            ->pluck('organizations.id')
            ->all();
    }

    /** Peut être responsable du planning hebdomadaire : agent ou admin actif (sinon il ne compte plus, tous le reçoivent) */
    public function canOwnWeeklyPlanning(): bool
    {
        return (bool) $this->active && $this->hasAnyRole(['admin', 'agent_communal']);
    }

    public function requestedReservations(): HasMany
    {
        return $this->hasMany(Reservation::class, 'requested_by_user_id');
    }

    public function validatedReservations(): HasMany
    {
        return $this->hasMany(Reservation::class, 'validated_by_user_id');
    }

    public function cancelledReservations(): HasMany
    {
        return $this->hasMany(Reservation::class, 'cancelled_by_user_id');
    }

    public function recordedCouncilDecisions(): HasMany
    {
        return $this->hasMany(CouncilDecision::class, 'recorded_by_user_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    public function createdUnavailabilities(): HasMany
    {
        return $this->hasMany(Unavailability::class, 'created_by_user_id');
    }

    public function unavailabilityHistoryChanges(): HasMany
    {
        return $this->hasMany(UnavailabilityHistory::class, 'changed_by_user_id');
    }

    public function reservationStatusHistoryChanges(): HasMany
    {
        return $this->hasMany(ReservationStatusHistory::class, 'changed_by_user_id');
    }

    public function reservationAssignmentHistoryChanges(): HasMany
    {
        return $this->hasMany(ReservationAssignmentHistory::class, 'changed_by_user_id');
    }

    public function resolvedReservationConflicts(): HasMany
    {
        return $this->hasMany(ReservationConflict::class, 'resolved_by_user_id');
    }

    public function orgRegistrationRequests(): HasMany
    {
        return $this->hasMany(OrgRegistrationRequest::class, 'request_by_user_id');
    }

    public function reviewedOrgRegistrationRequests(): HasMany
    {
        return $this->hasMany(OrgRegistrationRequest::class, 'reviewed_by_user_id');
    }

    public function userOrgRequests(): HasMany
    {
        return $this->hasMany(UserOrgRequest::class, 'requested_by_user_id');
    }

    public function reviewedUserOrgRequests(): HasMany
    {
        return $this->hasMany(UserOrgRequest::class, 'reviewed_by_user_id');
    }

    public function notificationDeliveries(): MorphMany
    {
        return $this->morphMany(NotificationDelivery::class, 'notifiable');
    }

    /** Envoi en file d'attente : l'inscription et le renvoi ne dépendent pas du serveur SMTP. */
    public function sendEmailVerificationNotification(): void
    {
        $this->notify(new QueuedVerifyEmail);
    }

    /** Envoi en file d'attente : POST /auth/forgot-password ne dépend pas du serveur SMTP. */
    public function sendPasswordResetNotification(#[\SensitiveParameter] $token): void
    {
        $this->notify(new QueuedResetPassword($token));
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'email_verified_at' => 'datetime',
            'tours_seen' => 'array',
            'password' => 'hashed',
        ];
    }
}
