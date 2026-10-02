<?php

declare(strict_types=1);

namespace Fruitcake\PhpUnitTia\Laravel\Tests\Support;

/**
 * A throwaway project root for tests that read files from disk.
 */
trait TemporaryProject
{
    private string $root;

    protected function setUpTemporaryProject(): void
    {
        $this->root = sys_get_temp_dir().'/phpunit-tia-laravel-'.bin2hex(random_bytes(6));
        mkdir($this->root, 0755, true);
    }

    protected function tearDownTemporaryProject(): void
    {
        if (! isset($this->root) || ! is_dir($this->root)) {
            return;
        }

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }

        rmdir($this->root);
    }

    protected function write(string $relative, string $contents): void
    {
        $path = $this->root.'/'.$relative;

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }

        file_put_contents($path, $contents);
    }
}
