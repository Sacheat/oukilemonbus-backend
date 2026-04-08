<?php

namespace App\Models;

use App\Enums\UnavailabilityReasonType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'reason_type',
    'reason',
    'driver_id',
    'vehicle_id',
    'start_datetime',
    'end_datetime',
    'created_by_user_id',
])]
class Unavailability extends Model
{
    use HasFactory;

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function history(): HasMany
    {
        return $this->hasMany(UnavailabilityHistory::class);
    }

    protected function casts(): array
    {
        return [
            'reason_type' => UnavailabilityReasonType::class,
            'start_datetime' => 'datetime',
            'end_datetime' => 'datetime',
        ];
    }
}
