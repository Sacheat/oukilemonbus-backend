<?php

namespace App\Services\Locations;

/**
 * Codes postaux belges → localités, depuis database/data/be_postal_codes.csv
 * (GeoNames, export postal codes BE, licence CC BY 4.0 : https://www.geonames.org).
 *
 * Sert à LocationService quand ORS ne renvoie pas la localité d'une adresse (fréquent en Belgique) :
 * un code postal couvre souvent plusieurs localités (5500 : Dinant, Anseremme, Bouvignes…),
 * `nearestLocality()` retient la plus proche des coordonnées de l'adresse.
 *
 * Mise à jour du fichier : télécharger https://download.geonames.org/export/zip/BE.zip et garder
 * les colonnes code postal, nom, latitude, longitude.
 */
class BelgianPostalCodes
{
    /** @var array<string, array<int, array{locality: string, latitude: float, longitude: float}>>|null */
    private static ?array $byPostalCode = null;

    public function nearestLocality(string $postalCode, float $latitude, float $longitude): ?string
    {
        $candidates = $this->all()[trim($postalCode)] ?? [];

        // Distance approchée (équirectangulaire) : suffisante pour départager des localités voisines.
        $cosLat = cos(deg2rad($latitude));

        return collect($candidates)
            ->sortBy(fn (array $c): float => (($c['latitude'] - $latitude) ** 2) + ((($c['longitude'] - $longitude) * $cosLat) ** 2))
            ->first()['locality'] ?? null;
    }

    /** @return array<string, array<int, array{locality: string, latitude: float, longitude: float}>> */
    private function all(): array
    {
        if (self::$byPostalCode !== null) {
            return self::$byPostalCode;
        }

        $byPostalCode = [];
        $handle = fopen(database_path('data/be_postal_codes.csv'), 'r');
        fgetcsv($handle, escape: ''); // en-tête

        while (($row = fgetcsv($handle, escape: '')) !== false) {
            [$postalCode, $locality, $latitude, $longitude] = $row;
            $byPostalCode[$postalCode][] = [
                'locality' => $locality,
                'latitude' => (float) $latitude,
                'longitude' => (float) $longitude,
            ];
        }

        fclose($handle);

        return self::$byPostalCode = $byPostalCode;
    }
}
