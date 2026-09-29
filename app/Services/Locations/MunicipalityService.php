<?php

namespace App\Services\Locations;

use App\Models\Location;
use App\Models\Municipality;
use Illuminate\Support\Facades\Schema;

/**
 * Vérifie si un lieu appartient aux localités de la commune active.
 *
 * `containsLocation()` normalise le nom de ville (ASCII, lowercase) et le compare
 * aux noms normalisés des localités actives de la commune active en base de données.
 * Si la table municipalities / municipality_localities n'existe pas encore (migration
 * non appliquée), bascule sur la liste de localités de config('municipality.localities').
 *
 * `name()` retourne le nom de la commune active ou la valeur config par défaut ('Dinant').
 * Utilisé par OrgRegistrationConfidenceScoreService pour personnaliser les messages du score.
 *
 * Fournit aussi à ReservationPreviewService les limites de distance de la commune active
 * (`maxTripDistanceKm()`, `maxPickupDistanceKm()`) et le point de référence (`referencePoint()`).
 */
class MunicipalityService
{
    public function containsLocation(?Location $location): bool
    {
        if (! $location || blank($location->city)) {
            return false;
        }

        return in_array(
            $this->normalize($location->city),
            $this->normalizedLocalities(),
            true
        );
    }

    public function name(): string
    {
        $municipality = $this->activeMunicipality();

        if ($municipality) {
            return $municipality->name;
        }

        return config('municipality.name', 'Dinant');
    }

    /** Distance maximale départ → destination : valeur de la commune active, sinon config. */
    public function maxTripDistanceKm(): int
    {
        return $this->activeMunicipality()?->max_trip_distance_km
            ?? (int) config('reservations.max_distance_km', 50);
    }

    /** Distance maximale point de référence → lieu de départ : valeur de la commune active, sinon config. */
    public function maxPickupDistanceKm(): int
    {
        return $this->activeMunicipality()?->max_pickup_distance_km
            ?? (int) config('reservations.max_pickup_distance_km', 50);
    }

    /**
     * Point d'où se mesure la distance jusqu'au lieu de départ : le dépôt du bus s'il est défini
     * et géolocalisé, sinon le centre de la commune. Null si aucun des deux n'est connu
     * (la règle n'est alors pas appliquée).
     *
     * @return array{latitude: float, longitude: float, label: string}|null
     */
    public function referencePoint(): ?array
    {
        $municipality = $this->activeMunicipality();

        if (! $municipality) {
            return null;
        }

        $depot = $municipality->depotLocation;
        if ($depot && filled($depot->latitude) && filled($depot->longitude)) {
            return [
                'latitude' => (float) $depot->latitude,
                'longitude' => (float) $depot->longitude,
                'label' => 'du dépôt',
            ];
        }

        if (filled($municipality->latitude) && filled($municipality->longitude)) {
            return [
                'latitude' => (float) $municipality->latitude,
                'longitude' => (float) $municipality->longitude,
                'label' => "du centre de {$municipality->name}",
            ];
        }

        return null;
    }

    private function normalizedLocalities(): array
    {
        $municipality = $this->activeMunicipality();

        if ($municipality) {
            return $municipality->activeLocalities
                ->pluck('normalized_name')
                ->all();
        }

        return collect(config('municipality.localities', []))
            ->map(fn (string $locality): string => $this->normalize($locality))
            ->all();
    }

    private function activeMunicipality(): ?Municipality
    {
        if (! Schema::hasTable('municipalities') || ! Schema::hasTable('municipality_localities')) {
            return null;
        }

        return Municipality::query()
            ->with(['activeLocalities', 'depotLocation'])
            ->where('is_active', true)
            ->first();
    }

    private function normalize(string $value): string
    {
        return str($value)
            ->ascii()
            ->lower()
            ->trim()
            ->replaceMatches('/\s+/', ' ')
            ->toString();
    }
}
