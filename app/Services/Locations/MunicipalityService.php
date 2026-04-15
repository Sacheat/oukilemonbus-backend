<?php

namespace App\Services\Locations;

use App\Models\Location;

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
        return config('municipality.name', 'Dinant');
    }

    private function normalizedLocalities(): array
    {
        return collect(config('municipality.localities', []))
            ->map(fn (string $locality): string => $this->normalize($locality))
            ->all();
    }

    private function normalize(string $value): string
    {
        return str($value)->ascii()->lower()->trim()->toString();
    }
}
