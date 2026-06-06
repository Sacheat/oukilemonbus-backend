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
            ->with('activeLocalities')
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
