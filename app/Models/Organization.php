<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'name',
    'type',
    'email_contact',
    'phone_contact',
    'name_contact',
    'location_id',
    'is_priority',
    'annual_quota',
    'reservations_used_this_year',
    'quota_reset_year',
    'document_proof_path',
])]
class Organization extends Model
{
    use HasFactory;

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_organization')
            ->using(UserOrganization::class)
            ->withPivot(['relationship_type', 'is_default'])
            ->withTimestamps();
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class);
    }

    public function userOrgRequests(): HasMany
    {
        return $this->hasMany(UserOrgRequest::class);
    }

    protected function casts(): array
    {
        return [
            'is_priority' => 'boolean',
            'annual_quota' => 'integer',
            'reservations_used_this_year' => 'integer',
            'quota_reset_year' => 'datetime',
        ];
    }
}
