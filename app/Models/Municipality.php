<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'name', 'external_id', 'nis_code', 'country', 'postal_code', 'source', 'is_active', 'synced_at',
    'latitude', 'longitude', 'depot_location_id', 'max_trip_distance_km', 'max_pickup_distance_km',
    'on_call_name', 'on_call_email', 'on_call_phone',
])]
/**
 * Commune belge avec ses localités.
 * `nis_code` : code NIS officiel de la commune (identifiant unique national).
 * `synced_at` : dernière synchronisation depuis le fournisseur externe.
 * `activeLocalities()` : filtre utilitaire pour n'exposer que les localités actives.
 * `depotLocation` : lieu de départ du bus ; à défaut, `latitude`/`longitude` (centre de la commune)
 * servent de point de référence pour la distance maximale dépôt → lieu de départ.
 * `max_trip_distance_km` / `max_pickup_distance_km` : NULL = valeur de config/reservations.php.
 * `on_call_*` : contact de garde facultatif, prévenu par email des demandes d'aide des chauffeurs (DriverAlertService).
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

    public function depotLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'depot_location_id');
    }

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'synced_at' => 'datetime',
            'latitude' => 'decimal:8',
            'longitude' => 'decimal:8',
            'max_trip_distance_km' => 'integer',
            'max_pickup_distance_km' => 'integer',
        ];
    }
}
