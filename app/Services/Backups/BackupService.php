<?php

namespace App\Services\Backups;

use App\Services\Logging\DomainLogger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Throwable;

class BackupService
{
    public function __construct(
        private readonly DomainLogger $logger
    ) {}

    public function run(string $type = 'full', bool $includeLogs = false): array
    {
        $this->ensureSupportedType($type);

        $startedAt = CarbonImmutable::now();
        $backupName = sprintf(
            'bus-communal-%s-%s',
            $startedAt->format('Y-m-d-His'),
            $type
        );

        $backupPath = $this->backupPath();
        $archivePath = $backupPath.'/'.$backupName.'.tar.gz';

        $context = [
            'backup_name' => $backupName,
            'type' => $type,
            'include_logs' => $includeLogs,
            'path' => $archivePath,
        ];

        $this->logger->backupStarted($context);

        try {
            File::ensureDirectoryExists($backupPath);

            $metadata = $this->metadata($backupName, $type, $includeLogs, $startedAt);

            if ($this->includesDatabase($type)) {
                $metadata['database'] = true;
            }

            if ($this->includesFiles($type)) {
                $metadata['files'] = true;
            }

            if ($includeLogs) {
                $metadata['logs'] = true;
            }

            $archivePath = $this->archiveBackup($archivePath, $metadata, $type, $includeLogs);
            $size = File::size($archivePath);

            $this->pruneOldBackups();

            $summary = [
                ...$context,
                'path' => $archivePath,
                'size' => $size,
                'created_at' => $startedAt->toISOString(),
            ];

            $this->logger->backupSucceeded($summary);

            return $summary;
        } catch (Throwable $exception) {
            File::delete($archivePath);

            $this->logger->backupFailed($context, $exception);

            throw $exception;
        }
    }

    public function list(): array
    {
        $backupPath = $this->backupPath();

        if (! File::isDirectory($backupPath)) {
            return [];
        }

        return collect(File::files($backupPath))
            ->filter(fn (SplFileInfo $file): bool => str_ends_with($file->getFilename(), '.tar.gz'))
            ->sortByDesc(fn (SplFileInfo $file): int => $file->getMTime())
            ->map(fn (SplFileInfo $file): array => [
                'name' => $file->getFilename(),
                'path' => $file->getRealPath(),
                'size' => $file->getSize(),
                'created_at' => CarbonImmutable::createFromTimestamp($file->getMTime())->toDateTimeString(),
            ])
            ->values()
            ->all();
    }

    public function pruneOldBackups(): int
    {
        $retentionDays = (int) config('backups.retention_days', 14);

        if ($retentionDays <= 0 || ! File::isDirectory($this->backupPath())) {
            return 0;
        }

        $threshold = CarbonImmutable::now()->subDays($retentionDays)->getTimestamp();
        $deleted = 0;

        foreach (File::files($this->backupPath()) as $file) {
            if (! str_ends_with($file->getFilename(), '.tar.gz')) {
                continue;
            }

            if ($file->getMTime() >= $threshold) {
                continue;
            }

            File::delete($file->getRealPath());
            $deleted++;
        }

        return $deleted;
    }

    private function dumpDatabase(): string
    {
        $driver = DB::connection()->getDriverName();

        if ($driver !== 'mysql') {
            throw new InvalidArgumentException("Le backup DB V1 supporte uniquement MySQL, driver actuel: {$driver}.");
        }

        $database = DB::connection()->getDatabaseName();
        $lines = [
            '-- Bus Communal database backup',
            '-- Created at: '.CarbonImmutable::now()->toISOString(),
            '-- Database: '.$database,
            'SET FOREIGN_KEY_CHECKS=0;',
            '',
        ];

        foreach ($this->mysqlTables() as $table) {
            $quotedTable = $this->quoteIdentifier($table);
            $createTable = DB::selectOne('SHOW CREATE TABLE '.$quotedTable);
            $createStatement = (array) $createTable;
            $createSql = end($createStatement);

            $lines[] = 'DROP TABLE IF EXISTS '.$quotedTable.';';
            $lines[] = $createSql.';';
            $lines[] = '';

            DB::table($table)
                ->orderByRaw('1')
                ->chunk(500, function ($rows) use (&$lines, $quotedTable): void {
                    foreach ($rows as $row) {
                        $values = array_map(
                            fn (mixed $value): string => $this->sqlValue($value),
                            array_values((array) $row)
                        );

                        $columns = array_map(
                            fn (string $column): string => $this->quoteIdentifier($column),
                            array_keys((array) $row)
                        );

                        $lines[] = sprintf(
                            'INSERT INTO %s (%s) VALUES (%s);',
                            $quotedTable,
                            implode(', ', $columns),
                            implode(', ', $values)
                        );
                    }
                });

            $lines[] = '';
        }

        $lines[] = 'SET FOREIGN_KEY_CHECKS=1;';
        $lines[] = '';

        return implode(PHP_EOL, $lines);
    }

    private function mysqlTables(): array
    {
        return collect(DB::select('SHOW FULL TABLES WHERE Table_type = ?', ['BASE TABLE']))
            ->map(fn (object $row): string => (string) array_values((array) $row)[0])
            ->values()
            ->all();
    }

    private function writeConfiguredPath($archive, string $key, string $archiveRoot): void
    {
        $source = (string) config("backups.paths.{$key}");

        if (! File::isDirectory($source)) {
            return;
        }

        $source = realpath($source) ?: $source;

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            if ($this->isExcluded($item->getPathname())) {
                continue;
            }

            $relativePath = str_replace('\\', '/', ltrim(str_replace($source, '', $item->getPathname()), DIRECTORY_SEPARATOR));
            $archivePath = trim($archiveRoot.'/'.$relativePath, '/');

            if ($item->isDir()) {
                $this->writeTarEntry($archive, $archivePath.'/', '', true, $item->getMTime());

                continue;
            }

            $this->writeTarEntry(
                $archive,
                $archivePath,
                File::get($item->getPathname()),
                false,
                $item->getMTime()
            );
        }
    }

    private function archiveBackup(string $archivePath, array $metadata, string $type, bool $includeLogs): string
    {
        File::delete($archivePath);

        $archive = gzopen($archivePath, 'wb9');

        if ($archive === false) {
            throw new InvalidArgumentException('Impossible de creer l archive de backup.');
        }

        $this->writeTarEntry(
            $archive,
            'metadata.json',
            json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL,
            false,
            time()
        );

        if ($this->includesDatabase($type)) {
            $this->writeTarEntry($archive, 'database/', '', true, time());
            $this->writeTarEntry($archive, 'database/dump.sql', $this->dumpDatabase(), false, time());
        }

        if ($this->includesFiles($type)) {
            $this->writeTarEntry($archive, 'files/', '', true, time());
            $this->writeTarEntry($archive, 'files/private/', '', true, time());
            $this->writeConfiguredPath($archive, 'private', 'files/private');
            $this->writeTarEntry($archive, 'files/public/', '', true, time());
            $this->writeConfiguredPath($archive, 'public', 'files/public');
        }

        if ($includeLogs) {
            $this->writeTarEntry($archive, 'logs/', '', true, time());
            $this->writeConfiguredPath($archive, 'logs', 'logs');
        }

        gzwrite($archive, str_repeat("\0", 1024));
        gzclose($archive);

        return $archivePath;
    }

    private function writeTarEntry($archive, string $path, string $content, bool $isDirectory, int $modifiedAt): void
    {
        [$name, $prefix] = $this->tarNameParts($path);
        $size = $isDirectory ? 0 : strlen($content);

        $header = pack(
            'a100a8a8a8a12a12a8a1a100a6a2a32a32a8a8a155a12',
            $name,
            sprintf('%07o', $isDirectory ? 0755 : 0644),
            sprintf('%07o', 0),
            sprintf('%07o', 0),
            sprintf('%011o', $size),
            sprintf('%011o', $modifiedAt),
            '        ',
            $isDirectory ? '5' : '0',
            '',
            "ustar\0",
            '00',
            '',
            '',
            '',
            '',
            $prefix,
            ''
        );

        $checksum = 0;

        for ($index = 0; $index < 512; $index++) {
            $checksum += ord($header[$index]);
        }

        $header = substr_replace($header, sprintf('%06o'."\0".' ', $checksum), 148, 8);

        gzwrite($archive, $header);

        if ($isDirectory) {
            return;
        }

        gzwrite($archive, $content);

        $padding = 512 - ($size % 512);

        if ($padding < 512) {
            gzwrite($archive, str_repeat("\0", $padding));
        }
    }

    private function tarNameParts(string $path): array
    {
        if (strlen($path) <= 100) {
            return [$path, ''];
        }

        $name = basename($path);
        $prefix = dirname($path);

        if (strlen($name) > 100 || strlen($prefix) > 155) {
            throw new InvalidArgumentException("Le chemin {$path} est trop long pour l archive tar.");
        }

        return [$name, $prefix];
    }

    private function metadata(string $backupName, string $type, bool $includeLogs, CarbonImmutable $startedAt): array
    {
        return [
            'app' => config('app.name', 'Bus Communal'),
            'name' => $backupName,
            'created_at' => $startedAt->toISOString(),
            'type' => $type,
            'database' => false,
            'files' => false,
            'logs' => false,
            'include_logs' => $includeLogs,
            'environment' => app()->environment(),
            'encrypted' => false,
            'format' => 'tar.gz',
        ];
    }

    private function isExcluded(string $path): bool
    {
        $path = realpath($path) ?: $path;

        foreach (config('backups.exclude', []) as $excludedPath) {
            $excludedPath = realpath($excludedPath) ?: $excludedPath;

            if ($path === $excludedPath || str_starts_with($path, rtrim($excludedPath, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR)) {
                return true;
            }
        }

        return false;
    }

    private function backupPath(): string
    {
        return rtrim((string) config('backups.path'), DIRECTORY_SEPARATOR);
    }

    private function ensureSupportedType(string $type): void
    {
        if (! in_array($type, ['full', 'db', 'files'], true)) {
            throw new InvalidArgumentException('Le type de backup doit etre full, db ou files.');
        }
    }

    private function includesDatabase(string $type): bool
    {
        return in_array($type, ['full', 'db'], true);
    }

    private function includesFiles(string $type): bool
    {
        return in_array($type, ['full', 'files'], true);
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '`'.str_replace('`', '``', $identifier).'`';
    }

    private function sqlValue(mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return DB::connection()->getPdo()->quote((string) $value);
    }
}
