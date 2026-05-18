<?php

namespace App\Http\Requests\Documents;

use Illuminate\Foundation\Http\FormRequest;

class GenerateRouteSheetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date'],
        ];
    }

    public function messages(): array
    {
        return [
            'start_date.required' => 'La date de début est obligatoire.',
            'start_date.date_format' => 'La date de début doit être au format YYYY-MM-DD.',
            'end_date.required' => 'La date de fin est obligatoire.',
            'end_date.date_format' => 'La date de fin doit être au format YYYY-MM-DD.',
            'end_date.after_or_equal' => 'La date de fin doit être égale ou postérieure à la date de début.',
        ];
    }
}
