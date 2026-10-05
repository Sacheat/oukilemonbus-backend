<?php

namespace App\Http\Requests\Concerns;

/**
 * Champ `position` envoyé par le téléphone (étape de trajet, demande d'aide) :
 *   state       envoyee | refusee | indisponible (refus du partage ou GPS muet : l'action passe quand même)
 *   latitude, longitude, accuracy_m (mètres), captured_at (instant de la mesure) : seulement si envoyée.
 */
trait ValidatesDevicePosition
{
    /** @return array<string, list<string>> */
    protected function positionRules(): array
    {
        return [
            'position' => ['required', 'array'],
            'position.state' => ['required', 'in:envoyee,refusee,indisponible'],
            'position.latitude' => ['nullable', 'required_if:position.state,envoyee', 'numeric', 'between:-90,90'],
            'position.longitude' => ['nullable', 'required_if:position.state,envoyee', 'numeric', 'between:-180,180'],
            'position.accuracy_m' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'position.captured_at' => ['nullable', 'date'],
        ];
    }

    /** @return array<string, string> */
    protected function positionMessages(): array
    {
        return [
            'position.required' => 'Indiquez l’état de la position.',
            'position.state.required' => 'Indiquez l’état de la position.',
            'position.state.in' => 'État de la position inconnu.',
            'position.latitude.required_if' => 'La latitude manque.',
            'position.longitude.required_if' => 'La longitude manque.',
            'position.latitude.between' => 'Latitude invalide.',
            'position.longitude.between' => 'Longitude invalide.',
        ];
    }
}
