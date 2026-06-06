<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Resource document. Le `file_path` n'est jamais exposé — le téléchargement passe
 * par GET /api/documents/{document}/download qui vérifie les droits et retourne un token signé.
 * `generation_context` est restreint aux admins/agents (peut contenir des paramètres internes).
 */
class DocumentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,

            'reservation_id' => $this->reservation_id,
            'user_id' => $this->user_id,

            'document_type' => $this->document_type?->value ?? $this->document_type,
            'document_type_label' => $this->document_type?->label(),

            'file_name' => $this->file_name,
            'mime_type' => $this->mime_type,
            'file_size' => $this->file_size,
            'checksum' => $this->checksum,

            'generation_mode' => $this->generation_mode,
            'generation_context' => $this->when(
                $request->user()?->hasAnyRole(['admin', 'agent_communal']) === true,
                $this->generation_context
            ),

            'template_key' => $this->template_key,
            'template_version' => $this->template_version,

            'generated_at' => $this->generated_at?->toISOString(),

            'is_official' => $this->is_official,
            'version' => $this->version,
            'is_available' => $this->is_available,

            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),

            'reservation' => new ReservationResource($this->whenLoaded('reservation')),
            'user' => new UserResource($this->whenLoaded('user')),
        ];
    }
}
