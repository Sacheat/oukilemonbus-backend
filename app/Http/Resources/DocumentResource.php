<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Resource pour les documents générés, incluant les champs de génération
 * - checksum : pour identifier de manière unique le contenu généré, utile pour éviter les doublons
 * - generation_context : pour stocker les paramètres de génération (ex: modele officiel, etc.)
 * - template_key et template_version : pour identifier le template utilisé lors de la génération
 *
 * PAS DE FILE PATH EXPOSÉ DANS LE RESOURCE POUR ÉVITER LES PROBLÈMES DE SÉCURITÉ
 * il sera utilisé en interne pour la génération de liens de téléchargement sécurisés via : GET /api/documents/{document}/download
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
