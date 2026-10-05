<?php

namespace App\Http\Requests\DriverJourney;

use App\Http\Requests\Concerns\ValidatesDevicePosition;
use Illuminate\Foundation\Http\FormRequest;

/** Corps de PATCH /api/driver-alerts/{alert}/position (« Renvoyer ma position »). */
class UpdateDriverAlertPositionRequest extends FormRequest
{
    use ValidatesDevicePosition;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return $this->positionRules();
    }

    public function messages(): array
    {
        return $this->positionMessages();
    }
}
