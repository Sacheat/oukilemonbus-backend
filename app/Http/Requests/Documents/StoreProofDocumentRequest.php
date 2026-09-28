<?php

namespace App\Http\Requests\Documents;

use Illuminate\Foundation\Http\FormRequest;

class StoreProofDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'proof_document' => [
                'required',
                'file',
                'mimes:pdf,jpg,jpeg,png',
                'max:5120',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'proof_document.required' => 'Un fichier justificatif est requis.',
            'proof_document.file'     => 'Le champ doit être un fichier.',
            'proof_document.mimes'    => 'Seuls les formats PDF, JPG et PNG sont acceptés.',
            'proof_document.max'      => 'Le fichier ne doit pas dépasser 5 Mo.',
        ];
    }
}