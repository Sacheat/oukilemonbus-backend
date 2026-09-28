<?php

declare(strict_types=1);

namespace App\Tooling\Inspector\Http\Resources;

use App\Tooling\Inspector\Services\FlowRegistry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Représentation JSON d'une entrée du Backend Inspector.
 * Le flow est résumé (titre, catégorie) en liste ; sa fiche complète n'est incluse que via withFullFlow().
 */
final class InspectorEntryResource extends JsonResource
{
    private bool $fullFlow = false;

    public function withFullFlow(): self
    {
        $this->fullFlow = true;

        return $this;
    }

    public function toArray(Request $request): array
    {
        $flow = app(FlowRegistry::class)->find($this->flow_key);

        return [
            'id' => $this->id,
            'user' => $this->user_id === null && $this->user_email === null ? null : [
                'id' => $this->user_id,
                'email' => $this->user_email,
            ],
            'method' => $this->method,
            'path' => $this->path,
            'route_name' => $this->route_name,
            'flow_key' => $this->flow_key,
            'flow' => $flow === null ? null : ($this->fullFlow ? $flow : [
                'title' => $flow['title'] ?? null,
                'category' => $flow['category'] ?? null,
            ]),
            'route_params' => $this->route_params,
            'http_status' => $this->http_status,
            'outcome' => $this->outcome,
            'duration_ms' => $this->duration_ms,
            'request_payload' => $this->request_payload,
            'response_summary' => $this->response_summary,
            'meta' => $this->meta,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
