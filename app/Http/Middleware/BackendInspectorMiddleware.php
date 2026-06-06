<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class BackendInspectorMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! config('backend_inspector.enabled', false)) {
            return $response;
        }

        if (! $this->shouldRecord($request, $response)) {
            return $response;
        }

        $actionKey = $this->resolveActionKey($request);

        if ($actionKey === null) {
            return $response;
        }

        $this->record($request, $actionKey, $this->buildContext($request, $response));

        return $response;
    }

    private function shouldRecord(Request $request, Response $response): bool
    {
        if (! in_array($request->method(), ['POST', 'PATCH', 'PUT', 'DELETE'], true)) {
            return false;
        }

        $status = $response->getStatusCode();

        return $status >= 200 && $status < 300;
    }

    private function resolveActionKey(Request $request): ?string
    {
        $routeName = $request->route()?->getName();

        if ($routeName === null) {
            return null;
        }

        $routeMap = config('backend_inspector.route_map', []);

        return $routeMap[$routeName] ?? null;
    }

    private function buildContext(Request $request, Response $response): array
    {
        $user = $request->user();

        $routeParams = [];
        foreach ($request->route()?->parameters() ?? [] as $key => $value) {
            $routeParams[$key . '_id'] = is_object($value) ? $value->getKey() : $value;
        }

        $requestFields = collect($request->except(['password', 'password_confirmation']))
            ->reject(fn(mixed $value): bool => $value instanceof \Illuminate\Http\UploadedFile)
            ->toArray();

        $responseActorId = null;
        $responseStatus  = null;

        try {
            $content = $response->getContent();
            if ($content) {
                $decoded = json_decode($content, true);
                $responseActorId = $decoded['data']['id'] ?? null;
                $responseStatus  = $decoded['data']['status'] ?? $decoded['status'] ?? null;
            }
        } catch (\Throwable) {
            // silent — response body parsing is best-effort
        }

        $actorId    = $user?->id ?? $responseActorId;
        $actorEmail = $user?->email;

        return array_filter([
            'actor_id'        => $actorId,
            'actor_email'     => $actorEmail,
            'status'          => 'success',
            'method'          => $request->method(),
            ...$routeParams,
            'request'         => $requestFields ?: null,
            'response_status' => $responseStatus,
        ], fn(mixed $v): bool => $v !== null);
    }

    private function record(Request $request, string $actionKey, array $context): void
    {
        if (! $request->hasSession()) {
            return;
        }

        $sessionKeys = config('backend_inspector.session_keys', []);
        $historyKey  = $sessionKeys['history'] ?? 'backend_inspector.history';
        $now         = now()->toDateTimeString();

        // History entry: strip the full request payload to keep session lean
        $historyItem = [
            'action_key'  => $actionKey,
            'context'     => array_diff_key($context, ['request' => true]),
            'recorded_at' => $now,
        ];

        $history = $request->session()->get($historyKey, []);
        array_unshift($history, $historyItem);
        $history = array_slice($history, 0, 8);

        $request->session()->put([
            $sessionKeys['last_action']      ?? 'backend_inspector.last_action'      => $actionKey,
            $sessionKeys['last_context']     ?? 'backend_inspector.last_context'     => $context,
            $sessionKeys['last_recorded_at'] ?? 'backend_inspector.last_recorded_at' => $now,
            $sessionKeys['current_actor_id'] ?? 'backend_inspector.current_actor_id' => $context['actor_id'] ?? null,
            $historyKey                                                               => $history,
        ]);
    }
}