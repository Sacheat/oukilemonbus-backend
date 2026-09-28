<?php

declare(strict_types=1);

namespace App\Tooling\Inspector\Http\Requests;

use App\Tooling\Inspector\Models\InspectorEntry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ListInspectorEntriesRequest extends FormRequest
{
    public function authorize(): bool
    {
        // L'accès est contrôlé par la permission tooling.access sur le groupe de routes.
        return true;
    }

    public function rules(): array
    {
        return [
            'user_id' => ['sometimes', 'integer'],
            'user_email' => ['sometimes', 'string', 'max:255'],
            'flow_key' => ['sometimes', 'string', 'max:255'],
            'route_name' => ['sometimes', 'string', 'max:255'],
            'method' => ['sometimes', Rule::in(['POST', 'PUT', 'PATCH', 'DELETE'])],
            'outcome' => ['sometimes', Rule::in([InspectorEntry::OUTCOME_SUCCESS, InspectorEntry::OUTCOME_ERROR])],
            'http_status' => ['sometimes', 'integer', 'between:100,599'],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date', 'after_or_equal:from'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
        ];
    }
}
