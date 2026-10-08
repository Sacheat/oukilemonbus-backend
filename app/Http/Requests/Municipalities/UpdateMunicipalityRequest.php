<?php

namespace App\Http\Requests\Municipalities;

use App\Models\Location;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMunicipalityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('municipalities.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:100'],
            'external_id' => ['sometimes', 'nullable', 'string', 'max:500'],
            'nis_code' => ['sometimes', 'nullable', 'string', 'max:20'],
            'country' => ['sometimes', 'string', 'size:2'],
            'postal_code' => ['sometimes', 'nullable', 'string', 'max:20'],
            'source' => ['sometimes', 'nullable', 'string', 'max:50'],
            'is_active' => ['sometimes', 'boolean'],
            'latitude' => ['sometimes', 'nullable', 'numeric', 'between:-90,90', 'required_with:longitude'],
            'longitude' => ['sometimes', 'nullable', 'numeric', 'between:-180,180', 'required_with:latitude'],
            'depot_location_id' => ['sometimes', 'nullable', 'integer', 'exists:locations,id', $this->geolocatedDepot(...)],
            'max_trip_distance_km' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:500'],
            'max_pickup_distance_km' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:500'],
            // Contact de garde (facultatif) : prévenu par email des demandes d'aide des chauffeurs
            'on_call_name' => ['sometimes', 'nullable', 'string', 'max:150'],
            'on_call_email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'on_call_phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            // « Ma journée » du chauffeur (null = valeur par défaut de config/driver_journey.php)
            // Réservé aux admins (un agent garde le reste de la page Communes)
            'navigation_engine' => ['sometimes', Rule::prohibitedIf(! $this->isAdmin()), 'nullable', 'string', Rule::in(array_keys(config('driver_journey.navigation_engines', [])))],
            'driver_departure_window_min' => ['sometimes', Rule::prohibitedIf(! $this->isAdmin()), 'nullable', 'integer', 'min:0', 'max:240'],
            // Planning hebdomadaire (admins) : null = valeur par défaut de config/weekly_planning.php
            'weekly_planning_enabled' => ['sometimes', Rule::prohibitedIf(! $this->isAdmin()), 'nullable', 'boolean'],
            'weekly_planning_send_day' => ['sometimes', Rule::prohibitedIf(! $this->isAdmin()), 'nullable', 'integer', 'between:1,7'],
            // Au quart d'heure : planning:send-weekly tourne toutes les 15 minutes (12:07 partirait en fait à 12:15)
            'weekly_planning_send_time' => [
                'sometimes', Rule::prohibitedIf(! $this->isAdmin()), 'nullable', 'date_format:H:i', 'regex:/^\d{2}:(00|15|30|45)$/',
            ],
            'weekly_planning_owner_user_id' => [
                'sometimes', Rule::prohibitedIf(! $this->isAdmin()), 'nullable', 'integer', $this->activeInternalUser(...),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'on_call_email.email' => 'L’adresse email du contact de garde n’est pas valide.',
            'on_call_name.max' => 'Le nom du contact de garde ne peut pas dépasser 150 caractères.',
            'on_call_phone.max' => 'Le téléphone du contact de garde ne peut pas dépasser 30 caractères.',
            'navigation_engine.prohibited' => 'Seul un administrateur peut choisir l’application de navigation des chauffeurs.',
            'driver_departure_window_min.prohibited' => 'Seul un administrateur peut régler le délai du premier départ.',
            'weekly_planning_enabled.prohibited' => 'Seul un administrateur peut régler l’envoi du planning hebdomadaire.',
            'weekly_planning_send_day.prohibited' => 'Seul un administrateur peut régler l’envoi du planning hebdomadaire.',
            'weekly_planning_send_time.prohibited' => 'Seul un administrateur peut régler l’envoi du planning hebdomadaire.',
            'weekly_planning_owner_user_id.prohibited' => 'Seul un administrateur peut désigner le responsable du planning.',
            'weekly_planning_send_day.between' => 'Choisissez un jour de la semaine.',
            'weekly_planning_send_time.date_format' => 'L’heure d’envoi doit être au format HH:MM.',
            'weekly_planning_send_time.regex' => 'Choisissez une heure au quart d’heure (00, 15, 30 ou 45) : l’envoi est vérifié toutes les 15 minutes.',
            'navigation_engine.in' => 'Cette application de navigation n’est pas proposée.',
            'driver_departure_window_min.integer' => 'Le délai du premier départ doit être un nombre de minutes.',
            'driver_departure_window_min.min' => 'Le délai du premier départ ne peut pas être négatif.',
            'driver_departure_window_min.max' => 'Le délai du premier départ ne peut pas dépasser 240 minutes (4 heures).',
        ];
    }

    /** Le responsable du planning est un agent ou un admin actif (il reçoit le planning et ses versions corrigées) */
    private function activeInternalUser(string $attribute, mixed $value, \Closure $fail): void
    {
        $user = User::query()->find($value);

        if ($user === null || ! $user->active || ! $user->hasAnyRole(['admin', 'agent_communal'])) {
            $fail('Le responsable du planning doit être un agent ou un administrateur actif.');
        }
    }

    private function isAdmin(): bool
    {
        return $this->user()?->hasRole('admin') ?? false;
    }

    /**
     * Le dépôt sert de point de référence pour la distance jusqu'au lieu de départ :
     * il doit donc avoir des coordonnées.
     */
    private function geolocatedDepot(string $attribute, mixed $value, \Closure $fail): void
    {
        $location = Location::find($value);

        if ($location && (blank($location->latitude) || blank($location->longitude))) {
            $fail('Le lieu choisi comme dépôt doit être géolocalisé (latitude et longitude).');
        }
    }
}
