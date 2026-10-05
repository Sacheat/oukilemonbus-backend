<?php

namespace App\Services\DriverJourney;

use App\Models\Municipality;

/**
 * Réglages de « Ma journée » pour la commune active : application de navigation, délai du premier départ, seuil des
 * étapes « loin du lieu prévu ». Valeur de la commune (page Communes, admins), sinon config/driver_journey.php.
 */
class DriverJourneySettingsService
{
    /** Moteurs disponibles : [clé => ['label', 'android', 'web', 'web_from']] */
    public function engines(): array
    {
        return config('driver_journey.navigation_engines', []);
    }

    public function engineKey(): string
    {
        $engines = $this->engines();
        $chosen = $this->municipality()?->navigation_engine;

        if ($chosen !== null && isset($engines[$chosen])) {
            return $chosen;
        }

        $default = (string) config('driver_journey.default_navigation_engine', 'openstreetmap');

        return isset($engines[$default]) ? $default : (string) array_key_first($engines);
    }

    public function departureWindowMinutes(): int
    {
        return max(0, (int) ($this->municipality()?->driver_departure_window_min ?? config('driver_journey.departure_window_minutes', 60)));
    }

    public function stepDistanceWarningMeters(): int
    {
        return max(0, (int) config('driver_journey.step_distance_warning_meters', 500));
    }

    /** Ce que lit le front (GET /driver-journey/settings) */
    public function forApi(): array
    {
        $key = $this->engineKey();

        return [
            'navigation_engine' => ['key' => $key, ...$this->engines()[$key]],
            'departure_window_minutes' => $this->departureWindowMinutes(),
            'step_distance_warning_meters' => $this->stepDistanceWarningMeters(),
            // Choix proposés à l'admin (page Communes) et valeurs par défaut de la config
            'navigation_engines' => collect($this->engines())
                ->map(fn (array $engine, string $key): array => ['key' => $key, 'label' => $engine['label']])
                ->values()
                ->all(),
            'default_navigation_engine' => (string) config('driver_journey.default_navigation_engine', 'openstreetmap'),
            'default_departure_window_minutes' => (int) config('driver_journey.departure_window_minutes', 60),
        ];
    }

    /** Lue à chaque appel : pas de cache, un réglage changé s'applique tout de suite */
    private function municipality(): ?Municipality
    {
        return Municipality::query()->where('is_active', true)->first();
    }
}
