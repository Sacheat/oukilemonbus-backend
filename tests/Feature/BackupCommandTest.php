<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class BackupCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $backupPath;

    private string $privatePath;

    private string $publicPath;

    protected function setUp(): void
    {
        parent::setUp();

        $basePath = storage_path('framework/testing/backups-module/'.md5($this->name().microtime(true)));

        $this->backupPath = $basePath.'/archives';
        $this->privatePath = $basePath.'/private';
        $this->publicPath = $basePath.'/public';

        File::deleteDirectory($basePath);
        File::ensureDirectoryExists($this->backupPath);
        File::ensureDirectoryExists($this->privatePath);
        File::ensureDirectoryExists($this->publicPath);

        config([
            'backups.path' => $this->backupPath,
            'backups.paths.private' => $this->privatePath,
            'backups.paths.public' => $this->publicPath,
            'backups.paths.logs' => storage_path('logs'),
            'backups.exclude' => [$this->backupPath],
            'backups.retention_days' => 14,
        ]);
    }

    public function test_it_creates_a_files_backup_archive_from_console_command(): void
    {
        File::ensureDirectoryExists($this->privatePath.'/documents');
        File::put($this->privatePath.'/documents/example.pdf', 'fake-pdf');
        File::put($this->publicPath.'/avatar.txt', 'fake-public-file');

        $this->artisan('backup:run', ['--only' => 'files'])
            ->expectsOutput('Création du backup files...')
            ->expectsOutput('Backup créé avec succès.')
            ->assertExitCode(0);

        $archives = collect(File::files($this->backupPath))
            ->filter(fn ($file): bool => str_ends_with($file->getFilename(), '.tar.gz'))
            ->values();

        $this->assertCount(1, $archives);
        $this->assertStringEndsWith('.tar.gz', $archives[0]->getFilename());
        $this->assertGreaterThan(0, $archives[0]->getSize());
    }

    public function test_it_creates_a_database_backup_archive_from_console_command(): void
    {
        $this->artisan('backup:run', ['--only' => 'db'])
            ->expectsOutput('Création du backup db...')
            ->expectsOutput('Backup créé avec succès.')
            ->assertExitCode(0);

        $archives = collect(File::files($this->backupPath))
            ->filter(fn ($file): bool => str_ends_with($file->getFilename(), '.tar.gz'))
            ->values();

        $this->assertCount(1, $archives);
        $this->assertStringEndsWith('.tar.gz', $archives[0]->getFilename());
        $this->assertGreaterThan(0, $archives[0]->getSize());
    }

    public function test_it_lists_available_backups(): void
    {
        File::put($this->backupPath.'/bus-communal-2026-05-20-120000-files.tar.gz', 'archive');

        $this->artisan('backup:list')
            ->expectsTable(
                ['Nom', 'Taille', 'Créé le', 'Chemin'],
                [[
                    'bus-communal-2026-05-20-120000-files.tar.gz',
                    '7 B',
                    now()->setTimestamp(File::lastModified($this->backupPath.'/bus-communal-2026-05-20-120000-files.tar.gz'))->toDateTimeString(),
                    realpath($this->backupPath.'/bus-communal-2026-05-20-120000-files.tar.gz'),
                ]]
            )
            ->assertExitCode(0);
    }

    public function test_it_rejects_an_invalid_backup_type(): void
    {
        $this->artisan('backup:run', ['--only' => 'users'])
            ->expectsOutput('Création du backup users...')
            ->expectsOutput('Le type de backup doit être full, db ou files.')
            ->assertExitCode(1);
    }
}
