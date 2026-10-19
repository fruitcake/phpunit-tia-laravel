<?php

declare(strict_types=1);

namespace Fruitcake\PhpUnitTia\Laravel;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Reads the files of a project directory into memory, for the indexes that
 * search them.
 *
 * @internal
 */
final class SourceFiles
{
    /**
     * @param  string  $path  project-relative directory, or a single file
     * @return array<string, string> project-relative path => source, sorted
     */
    public static function in(string $projectRoot, string $path, string $suffix): array
    {
        $root = rtrim($projectRoot, '/').'/';

        if (is_file($root.$path)) {
            $source = str_ends_with($path, $suffix) ? @file_get_contents($root.$path) : false;

            return $source === false ? [] : [$path => $source];
        }

        if (! is_dir($root.$path)) {
            return [];
        }

        $sources = [];
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root.$path, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($files as $file) {
            assert($file instanceof SplFileInfo);

            if (! $file->isFile() || ! str_ends_with($file->getPathname(), $suffix)) {
                continue;
            }

            $source = @file_get_contents($file->getPathname());

            if ($source !== false) {
                $sources[substr($file->getPathname(), strlen($root))] = $source;
            }
        }

        ksort($sources);

        return $sources;
    }
}
