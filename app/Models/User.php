<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
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

#[Fillable(['first_name', 'last_name', 'email', 'password', 'phone', 'active'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    public function driver(): HasOne
    {
        return $this->hasOne(Driver::class);
    }

    public function organizations(): BelongsToMany
    {
        return $this->belongsToMany(Organization::class, 'user_organization')
            ->using(UserOrganization::class)
            ->withPivot(['relationship_type', 'is_default'])
            ->withTimestamps();
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
            'password' => 'hashed',
        ];
    }
}
