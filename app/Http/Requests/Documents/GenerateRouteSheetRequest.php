<?php

namespace App\Http\Requests\Documents;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Requête de génération de feuille de route.
 * Deux modes mutuellement exclusifs :
 * - Mode journalier : service_date + vehicle_id (une journée, un véhicule)
 * - Mode période   : start_date + end_date (tous véhicules)
 * L'autorisation est vérifiée dans le contrôleur via Gate::authorize().
 */
class GenerateRouteSheetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'service_date' => ['required_without:start_date', 'date_format:Y-m-d'],
            'vehicle_id' => ['required_with:service_date', 'integer', 'exists:vehicles,id'],
            'start_date' => ['required_without:service_date', 'date_format:Y-m-d'],
            'end_date' => ['required_with:start_date', 'date_format:Y-m-d', 'after_or_equal:start_date'],
        ];
    }

    public function messages(): array
    {
        return [
            'service_date.required_without' => 'La date de service est obligatoire.',
            'service_date.date_format' => 'La date de service doit etre au format YYYY-MM-DD.',
            'vehicle_id.required_with' => 'Le vehicule est obligatoire pour generer une feuille de route journaliere.',
            'vehicle_id.integer' => 'Le vehicule selectionne est invalide.',
            'vehicle_id.exists' => 'Le vehicule selectionne est introuvable.',
            'start_date.required_without' => 'La date de debut est obligatoire.',
            'start_date.date_format' => 'La date de debut doit etre au format YYYY-MM-DD.',
            'end_date.required_with' => 'La date de fin est obligatoire.',
            'end_date.date_format' => 'La date de fin doit etre au format YYYY-MM-DD.',
            'end_date.after_or_equal' => 'La date de fin doit etre egale ou posterieure a la date de debut.',
        ];
    }
}
