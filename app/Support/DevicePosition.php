<?php

namespace App\Support;

use App\Enums\PositionState;
use Carbon\CarbonImmutable;

/**
 * Position envoyée par le téléphone avec une étape de trajet ou une demande d'aide (`position` validée par
 * ValidatesDevicePosition). Sans coordonnées, l'état devient « indisponible » même si le téléphone a dit « envoyée ».
 */
final class DevicePosition
{
    private function __construct(
        public readonly PositionState $state,
        public readonly ?float $latitude,
        public readonly ?float $longitude,
        public readonly ?int $accuracyM,
        public readonly ?CarbonImmutable $capturedAt
    ) {}

    /** @param  array<string, mixed>|null  $data  champ `position` validé */
    public static function fromArray(?array $data): self
    {
        $latitude = isset($data['latitude']) ? (float) $data['latitude'] : null;
        $longitude = isset($data['longitude']) ? (float) $data['longitude'] : null;
        $hasCoordinates = $latitude !== null && $longitude !== null;
        $state = PositionState::tryFrom((string) ($data['state'] ?? '')) ?? PositionState::INDISPONIBLE;

        if ($state === PositionState::ENVOYEE && ! $hasCoordinates) {
            $state = PositionState::INDISPONIBLE;
        }

        if ($state !== PositionState::ENVOYEE) {
            return new self($state, null, null, null, null);
        }

        return new self(
            $state,
            round($latitude, 7),
            round($longitude, 7),
            isset($data['accuracy_m']) ? (int) round((float) $data['accuracy_m']) : null,
            isset($data['captured_at']) ? CarbonImmutable::parse($data['captured_at'])->utc() : null
        );
    }

    /** Colonnes communes à trip_events et driver_alerts */
    public function toColumns(): array
    {
        return [
            'position_state' => $this->state,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'accuracy_m' => $this->accuracyM,
            'position_captured_at' => $this->capturedAt,
        ];
    }
}
