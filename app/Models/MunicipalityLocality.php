<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['municipality_id', 'name', 'normalized_name', 'external_id', 'source', 'is_active'])]
class MunicipalityLocality extends Model
{
    use HasFactory;

    public function municipality(): BelongsTo
    {
        return $this->belongsTo(Municipality::class);
    }

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }
}
