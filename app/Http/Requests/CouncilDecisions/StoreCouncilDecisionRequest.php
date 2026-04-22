<?php

namespace App\Http\Requests\CouncilDecisions;

use App\Enums\CouncilDecisionStatus;
use App\Models\CouncilDecision;
use App\Models\Reservation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCouncilDecisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $reservation = Reservation::query()->find($this->input('reservation_id'));

        return $reservation !== null
            && ($this->user()?->can('create', [CouncilDecision::class, $reservation]) ?? false);
    }

    public function rules(): array
    {
        return [
            'reservation_id' => ['required', 'integer', 'exists:reservations,id', 'unique:council_decisions,reservation_id'],
            'decision_status' => ['required', Rule::enum(CouncilDecisionStatus::class)],
            'decision_date' => ['sometimes', 'nullable', 'date'],
            'decision_reference' => ['sometimes', 'nullable', 'string', 'max:100'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }
}
