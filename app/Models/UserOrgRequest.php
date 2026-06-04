<?php

namespace App\Models;

use App\Enums\RequestStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Document;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'organization_id',
    'requested_by_user_id',
    'requested_role',
    'justification',
    'proof_document_id',
    'status',
    'confidence_note',
    'reviewed_by_user_id',
    'reviewed_at',
    'rejection_reason',
])]
class UserOrgRequest extends Model
{
    use HasFactory;

    protected $primaryKey = 'request_id';

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function requestedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function reviewedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }

    public function proofDocument(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'proof_document_id');
    }

    protected function casts(): array
    {
        return [
            'status' => RequestStatus::class,
            'confidence_note' => 'integer',
            'reviewed_at' => 'datetime',
        ];
    }
}
