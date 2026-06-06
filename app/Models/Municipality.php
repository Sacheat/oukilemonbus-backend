<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'external_id', 'nis_code', 'country', 'postal_code', 'source', 'is_active', 'synced_at'])]
/**
 * Commune belge avec ses localités.
 * `nis_code` : code NIS officiel de la commune (identifiant unique national).
 * `synced_at` : dernière synchronisation depuis le fournisseur externe.
 * `activeLocalities()` : filtre utilitaire pour n'exposer que les localités actives.
 */
class Municipality extends Model
{
    use HasFactory;

    public function localities(): HasMany
    {
        return $this->hasMany(MunicipalityLocality::class);
    }

    public function activeLocalities(): HasMany
    {
        return $this->hasMany(MunicipalityLocality::class)->where('is_active', true);
    }

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'synced_at' => 'datetime',
        ];
    }
}
