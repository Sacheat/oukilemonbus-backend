<?php

namespace Tests\Feature;

use App\Services\Backups\BackupEncryptor;
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

    public function test_an_encrypted_backup_leaves_no_plain_archive_and_decrypts_back(): void
    {
        config(['backups.encryption.enabled' => true, 'backups.encryption.key' => BackupEncryptor::generateKey()]);
        File::put($this->privatePath.'/justificatif.pdf', 'donnees-personnelles');

        $this->artisan('backup:run', ['--only' => 'files'])->assertExitCode(0);

        $files = collect(File::files($this->backupPath))->map->getFilename()->values();
        $this->assertCount(1, $files);
        $this->assertStringEndsWith('.tar.gz.enc', $files[0]);
        $encrypted = $this->backupPath.'/'.$files[0];
        $this->assertStringStartsWith('BCBK1', (string) file_get_contents($encrypted, length: 5));

        $this->artisan('backup:list')->assertExitCode(0);
        $this->artisan('backup:decrypt', ['archive' => $encrypted])->assertExitCode(0);

        $plain = substr($encrypted, 0, -4);
        $this->assertStringContainsString('donnees-personnelles', (string) gzdecode((string) file_get_contents($plain)));
        $this->assertStringContainsString('"encrypted": true', (string) gzdecode((string) file_get_contents($plain)));
    }

    public function test_decrypting_with_another_key_or_a_tampered_archive_fails(): void
    {
        config(['backups.encryption.enabled' => true, 'backups.encryption.key' => BackupEncryptor::generateKey()]);
        File::put($this->privatePath.'/justificatif.pdf', 'donnees-personnelles');
        $this->artisan('backup:run', ['--only' => 'files'])->assertExitCode(0);
        $encrypted = File::files($this->backupPath)[0]->getPathname();
        $key = config('backups.encryption.key');

        config(['backups.encryption.key' => BackupEncryptor::generateKey()]);
        $this->artisan('backup:decrypt', ['archive' => $encrypted])->assertExitCode(1);
        $this->assertFileDoesNotExist(substr($encrypted, 0, -4));

        config(['backups.encryption.key' => $key]);
        $bytes = (string) file_get_contents($encrypted);
        file_put_contents($encrypted, substr($bytes, 0, -1).chr(ord(substr($bytes, -1)) ^ 1));
        $this->artisan('backup:decrypt', ['archive' => $encrypted])->assertExitCode(1);
        $this->assertFileDoesNotExist(substr($encrypted, 0, -4));
    }

    public function test_encryption_handles_multi_chunk_and_empty_files(): void
    {
        $encryptor = new BackupEncryptor;
        $key = BackupEncryptor::generateKey();

        foreach (['', random_bytes(65536), random_bytes(200_003)] as $index => $content) {
            $source = $this->privatePath."/source-{$index}";
            File::put($source, $content);

            $encryptor->encrypt($source, "{$source}.enc", $key);
            $encryptor->decrypt("{$source}.enc", "{$source}.out", $key);

            $this->assertSame($content, file_get_contents("{$source}.out"));
        }
    }

    public function test_an_unencrypted_backup_is_refused_in_production(): void
    {
        $this->app['env'] = 'production';
        config(['backups.encryption.enabled' => false]);

        $this->artisan('backup:run', ['--only' => 'files'])->assertExitCode(1);

        $this->assertSame([], File::files($this->backupPath));
    }

    public function test_an_invalid_key_is_refused_before_any_archive_is_written(): void
    {
        config(['backups.encryption.enabled' => true, 'backups.encryption.key' => 'pas-une-cle']);

        $this->artisan('backup:run', ['--only' => 'files'])->assertExitCode(1);

        $this->assertSame([], File::files($this->backupPath));
    }
}
