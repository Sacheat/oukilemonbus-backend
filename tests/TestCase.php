<?php

namespace Tests;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\File;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance('files', new class extends Filesystem
        {
            public function replace($path, $content, $mode = null): void
            {
                clearstatcache(true, $path);

                $path = realpath($path) ?: $path;

                if (! $this->isDirectory(dirname($path))) {
                    $this->makeDirectory(dirname($path), 0777, true, true);
                }

                file_put_contents($path, $content, LOCK_EX);

                if ($mode !== null) {
                    @chmod($path, $mode);
                }
            }
        });

        $compiledViewPath = storage_path(
            implode(DIRECTORY_SEPARATOR, [
                'framework',
                'testing',
                'views',
                md5(static::class.'::'.$this->name()),
            ])
        );

        File::deleteDirectory($compiledViewPath);
        File::ensureDirectoryExists($compiledViewPath);

        config(['view.compiled' => $compiledViewPath]);
    }
}
