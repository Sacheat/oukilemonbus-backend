<?php

declare(strict_types=1);

namespace App\Tooling\Toolbox\Services;

use App\Tooling\Support\LocalDateBounds;
use App\Tooling\Support\PayloadSanitizer;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Jobs en échec de la file d'attente (table `queue.failed.table`, pilote database-uuids).
 *
 * Lecture paginée et détail expurgé : le payload n'est jamais renvoyé brut. La commande sérialisée
 * (`data.command`, qui peut contenir un jeton de réinitialisation ou des données personnelles) est
 * masquée, et les clés sensibles du reste du payload le sont comme dans le journal de l'Inspector.
 * Relance et suppression reprennent queue:retry et queue:forget / queue:flush.
 */
final class FailedJobsService
{
    private const SUMMARY_LENGTH = 300;

    private const EXCEPTION_MAX_LENGTH = 20000;

    public function __construct(
        private readonly PayloadSanitizer $sanitizer,
    ) {}

    public function paginate(?string $queue, int $perPage): LengthAwarePaginator
    {
        return $this->table()
            ->when($queue !== null, fn (Builder $q) => $q->where('queue', $queue))
            ->orderByDesc('failed_at')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->through(fn (object $row): array => $this->summary($row));
    }

    public function find(string $uuid): ?array
    {
        $row = $this->table()->where('uuid', $uuid)->first();

        if ($row === null) {
            return null;
        }

        return [
            ...$this->summary($row),
            'exception' => Str::limit((string) $row->exception, self::EXCEPTION_MAX_LENGTH),
            'payload' => $this->safePayload((string) $row->payload),
        ];
    }

    public function exists(string $uuid): bool
    {
        return $this->table()->where('uuid', $uuid)->exists();
    }

    /** Remet les jobs en file comme `queue:retry` (tentatives remises à zéro, puis suppression de failed_jobs). */
    public function retry(?string $uuid = null): int
    {
        $ids = $uuid !== null ? [$uuid] : $this->table()->pluck('uuid')->all();

        if ($ids === []) {
            return 0;
        }

        Artisan::call('queue:retry', ['id' => $ids]);

        return count($ids) - $this->table()->whereIn('uuid', $ids)->count();
    }

    /** Supprime un job (`queue:forget`), ou tous ceux qui ont échoué avant `before` (`queue:flush`). */
    public function delete(?string $uuid = null, ?string $before = null): int
    {
        return $this->table()
            ->when($uuid !== null, fn (Builder $q) => $q->where('uuid', $uuid))
            ->when($before !== null, fn (Builder $q) => LocalDateBounds::applyBefore($q, 'failed_at', $before))
            ->delete();
    }

    private function summary(object $row): array
    {
        $payload = json_decode((string) $row->payload, true) ?: [];

        return [
            'id' => (int) $row->id,
            'uuid' => $row->uuid,
            'queue' => $row->queue,
            'connection' => $row->connection,
            'job' => $payload['displayName'] ?? ($payload['job'] ?? null),
            'exception_summary' => Str::limit(strtok((string) $row->exception, "\n") ?: '', self::SUMMARY_LENGTH),
            'failed_at' => $row->failed_at
                ? Carbon::parse($row->failed_at, (string) config('app.timezone', 'UTC'))->toISOString()
                : null,
        ];
    }

    private function safePayload(string $raw): ?array
    {
        $payload = json_decode($raw, true);

        if (! is_array($payload)) {
            return null;
        }

        if (Arr::has($payload, 'data.command')) {
            Arr::set($payload, 'data.command', '[objet sérialisé masqué]');
        }

        return $this->sanitizer->sanitize($payload);
    }

    private function table(): Builder
    {
        return DB::connection(config('queue.failed.database'))->table((string) config('queue.failed.table', 'failed_jobs'));
    }
}
