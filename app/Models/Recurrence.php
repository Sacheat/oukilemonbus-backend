<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'frequency',
    'interval_value',
    'days_of_week',
    'start_date',
    'end_date',
    'occurrence_count',
    'is_active',
])]
class Recurrence extends Model
{
    use HasFactory;

    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class);
    }

    protected function casts(): array
    {
        return [
            'interval_value' => 'integer',
            'start_date' => 'date',
            'end_date' => 'date',
            'occurrence_count' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
