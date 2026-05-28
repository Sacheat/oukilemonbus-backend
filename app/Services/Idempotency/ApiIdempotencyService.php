<?php

namespace App\Services\Idempotency;

use App\Models\ApiIdempotencyKey;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class ApiIdempotencyService
{
    /**
     * @param  callable(): array{status:int, body:array}  $action
     * @return array{status:int, body:array}
     */
    public function execute(User $user, string $key, string $method, string $routeName, string $requestHash, callable $action): array
    {
        if (! Schema::hasTable('api_idempotency_keys')) {
            return $action();
        }

        return DB::transaction(function () use ($user, $key, $method, $routeName, $requestHash, $action): array {
            $record = ApiIdempotencyKey::query()
                ->where('user_id', $user->id)
                ->where('idempotency_key', $key)
                ->where('method', $method)
                ->where('route_name', $routeName)
                ->lockForUpdate()
                ->first();

            if ($record !== null) {
                if ($record->request_hash !== $requestHash) {
                    throw ValidationException::withMessages([
                        'idempotency_key' => 'Cette cle idempotente est deja utilisee avec un payload different.',
                    ]);
                }

                if ($record->completed_at === null) {
                    return [
                        'status' => 409,
                        'body' => ['message' => 'Une requete identique est deja en cours de traitement.'],
                    ];
                }

                return [
                    'status' => (int) $record->response_status,
                    'body' => (array) ($record->response_body ?? []),
                ];
            }

            $record = ApiIdempotencyKey::query()->create([
                'user_id' => $user->id,
                'idempotency_key' => $key,
                'method' => $method,
                'route_name' => $routeName,
                'request_hash' => $requestHash,
            ]);

            $response = $action();

            $record->update([
                'response_status' => $response['status'],
                'response_body' => $response['body'],
                'completed_at' => now(),
            ]);

            return $response;
        });
    }
}
