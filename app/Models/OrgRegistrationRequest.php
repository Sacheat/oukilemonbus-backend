<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'request_by_user_id',
    'organization_name',
    'organization_type',
    'enterprise_number',
    'official_email',
    'phone',
    'location_id',
    'contact_person_name',
    'contact_person_role',
    'proof_document_path',
    'comment',
    'status',
    'reviewed_by_user_id',
    'reviewed_at',
    'rejection_reason',
])]
class OrgRegistrationRequest extends Model
{
    use HasFactory;

    public function requestedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'request_by_user_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function reviewedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }

    protected function casts(): array
    {
        return [
            'reviewed_at' => 'datetime',
        ];
    }
}
