<?php

namespace App\Http\Requests\Documents;

use App\Enums\DocumentType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Filtres de GET /api/documents : document_type[] / exclude_type[] (valeurs de DocumentType), date_from / date_to
 * (jour à Bruxelles, sur generated_at sinon created_at), q (nom de fichier, numéro exact de réservation, objet du
 * trajet), reservation_id, per_page (1 à 100, 15 par défaut).
 */
class ListDocumentsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'document_type' => ['nullable', 'array'],
            'document_type.*' => ['string', Rule::enum(DocumentType::class)],
            'exclude_type' => ['nullable', 'array'],
            'exclude_type.*' => ['string', Rule::enum(DocumentType::class)],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'q' => ['nullable', 'string', 'max:100'],
            'reservation_id' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    public function messages(): array
    {
        return [
            'document_type.*.enum' => 'Type de document inconnu.',
            'exclude_type.*.enum' => 'Type de document inconnu.',
            'date_from.date_format' => 'La date de début doit être au format AAAA-MM-JJ.',
            'date_to.date_format' => 'La date de fin doit être au format AAAA-MM-JJ.',
            'date_to.after_or_equal' => 'La date de fin doit être postérieure ou égale à la date de début.',
        ];
    }
}
