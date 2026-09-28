<?php

declare(strict_types=1);

namespace App\Tooling\Inspector\Services;

use App\Models\User;
use App\Tooling\Inspector\Models\InspectorEntry;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Construit et persiste une entrée du Backend Inspector à partir d'un couple requête/réponse.
 *
 * `shouldRecord()` filtre selon la config (activation, méthodes, routes ignorées).
 * `record()` expurge le payload (mots de passe, tokens, fichiers) avant l'écriture en base :
 * aucun secret ne doit jamais atteindre la table `inspector_entries`.
 */
final class InspectorRecorder
{
    private const REDACTED = '[masqué]';

    private const MAX_STRING_LENGTH = 1000;

    public function shouldRecord(Request $request): bool
    {
        if (! config('tooling.inspector.enabled', false)) {
            return false;
        }

        $methods = (array) config('tooling.inspector.methods', []);

        if (! in_array($request->method(), $methods, true)) {
            return false;
        }

        $routeName = $request->route()?->getName();

        return $routeName === null
            || ! Str::is((array) config('tooling.inspector.ignored_routes', []), $routeName);
    }

    public function record(Request $request, Response $response, ?User $actor, float $startedAt): InspectorEntry
    {
        $routeName = $request->route()?->getName();
        $httpStatus = $response->getStatusCode();

        return InspectorEntry::query()->create([
            'user_id' => $actor?->getKey(),
            'user_email' => $actor?->email,
            'method' => $request->method(),
            'path' => Str::limit($request->getPathInfo(), 2000, ''),
            'route_name' => $routeName,
            'flow_key' => $this->resolveFlowKey($routeName),
            'route_params' => $this->routeParams($request) ?: null,
            'http_status' => $httpStatus,
            'outcome' => $httpStatus >= 400 ? InspectorEntry::OUTCOME_ERROR : InspectorEntry::OUTCOME_SUCCESS,
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'request_payload' => $this->payload($request),
            'response_summary' => $this->responseSummary($response),
            'meta' => array_filter([
                'ip' => $request->ip(),
                'user_agent' => Str::limit((string) $request->userAgent(), 255, ''),
                'idempotency_key' => $request->header('Idempotency-Key'),
            ]),
        ]);
    }

    private function resolveFlowKey(?string $routeName): ?string
    {
        if ($routeName === null) {
            return null;
        }

        return config('tooling.inspector.route_map', [])[$routeName] ?? null;
    }

    private function routeParams(Request $request): array
    {
        $params = [];

        foreach ($request->route()?->parameters() ?? [] as $key => $value) {
            $params[$key] = is_object($value) && method_exists($value, 'getKey') ? $value->getKey() : $value;
        }

        return $params;
    }

    private function payload(Request $request): ?array
    {
        $payload = $this->sanitize(array_replace_recursive($request->all(), $request->allFiles()));

        if ($payload === []) {
            return null;
        }

        $maxBytes = (int) config('tooling.inspector.max_payload_bytes', 16384);
        $size = strlen((string) json_encode($payload));

        if ($size > $maxBytes) {
            return ['_truncated' => true, '_size_bytes' => $size, '_keys' => array_keys($payload)];
        }

        return $payload;
    }

    private function sanitize(array $data): array
    {
        $result = [];

        foreach ($data as $key => $value) {
            if (is_string($key) && $this->isSensitiveKey($key)) {
                $result[$key] = self::REDACTED;
            } elseif ($value instanceof UploadedFile) {
                $result[$key] = sprintf('[fichier : %s, %d o]', $value->getClientOriginalName(), $value->getSize());
            } elseif (is_array($value)) {
                $result[$key] = $this->sanitize($value);
            } elseif (is_string($value)) {
                $result[$key] = Str::limit($value, self::MAX_STRING_LENGTH);
            } else {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    private function isSensitiveKey(string $key): bool
    {
        return Str::contains(
            Str::lower($key),
            array_map('strtolower', (array) config('tooling.inspector.redacted_keys', []))
        );
    }

    // Résumé best-effort : id et statut de la ressource renvoyée, ou message et erreurs de validation.
    private function responseSummary(Response $response): ?array
    {
        $decoded = json_decode((string) $response->getContent(), true);

        if (! is_array($decoded)) {
            return null;
        }

        $data = is_array($decoded['data'] ?? null) ? $decoded['data'] : [];

        $summary = array_filter([
            'resource_id' => $data['id'] ?? null,
            'resource_status' => is_scalar($data['status'] ?? null) ? $data['status'] : null,
            'message' => is_string($decoded['message'] ?? null) ? Str::limit($decoded['message'], 500) : null,
            'errors' => is_array($decoded['errors'] ?? null) ? $this->sanitize($decoded['errors']) : null,
        ], fn (mixed $value): bool => $value !== null);

        return $summary ?: null;
    }
}
