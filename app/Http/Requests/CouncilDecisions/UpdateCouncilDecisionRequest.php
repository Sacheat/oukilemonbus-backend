<?php

namespace App\Http\Requests\CouncilDecisions;

use App\Enums\CouncilDecisionStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCouncilDecisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $councilDecision = $this->route('councilDecision');

        return is_object($councilDecision)
            && ($this->user()?->can('update', $councilDecision) ?? false);
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
