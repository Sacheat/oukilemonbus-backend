<?php

namespace App\Http\Requests\Recurrences;

class StoreRecurrenceRequest extends PreviewRecurrenceRequest
{
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'requested_by_user_id' => ['sometimes', 'integer', 'exists:users,id'],
        ]);
    }
}