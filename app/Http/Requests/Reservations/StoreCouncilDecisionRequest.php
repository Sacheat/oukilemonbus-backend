<?php

namespace App\Http\Requests\Reservations;

use App\Enums\CouncilDecisionStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCouncilDecisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $reservation = $this->route('reservation');

        return is_object($reservation)
            && ($this->user()?->can('recordCouncilDecision', $reservation) ?? false);
    }

    public function rules(): array
    {
        return [
            'decision_status' => ['required', Rule::enum(CouncilDecisionStatus::class)],
            'decision_date' => ['sometimes', 'nullable', 'date'],
            'decision_reference' => ['sometimes', 'nullable', 'string', 'max:100'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }
}
