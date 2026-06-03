<?php

namespace App\Http\Requests\Recurrences;

use Illuminate\Foundation\Http\FormRequest;

class CancelRecurrenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('recurrences.cancel') ?? false;
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:500'],
        ];
    }
}