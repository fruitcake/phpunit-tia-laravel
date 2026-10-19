<?php

declare(strict_types=1);

namespace Fruitcake\PhpUnitTia\Laravel\Resolvers;

use PHPUnit\TextUI\Configuration\Registry;
use Throwable;

/**
 * Where a project's test code lives: the test suite directories of
 * phpunit.xml, and the autoload-dev paths of composer.json. A base TestCase,
 * its traits and helpers usually sit next to the suites (tests/TestCase.php
 * beside tests/Unit and tests/Feature), not in them.
 *
 * @internal
 */
final class TestCode
{
    /** @var list<string> project-relative directories and files */
    public readonly array $paths;

    /**
     * @param  list<string>|null  $suiteDirectories  null for those of phpunit.xml
     */
    public function __construct(string $projectRoot, ?array $suiteDirectories = null)
    {
        $projectRoot = rtrim($projectRoot, '/');
        $paths = [...($suiteDirectories ?? self::suiteDirectories($projectRoot)), ...self::autoloadDevPaths($projectRoot)];
        $paths = array_values(array_unique(array_filter(array_map(
            static fn (string $path): string => trim((string) preg_replace('#^\./#', '', $path), '/'),
            $paths,
        ), static fn (string $path): bool => $path !== '' && $path !== '.')));

        $this->paths = $paths === [] ? ['tests'] : $paths;
    }

    public function contains(string $relativePath): bool
    {
        foreach ($this->paths as $path) {
            if ($relativePath === $path || str_starts_with($relativePath, $path.'/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private static function suiteDirectories(string $projectRoot): array
    {
        $directories = [];
        $root = (realpath($projectRoot) ?: $projectRoot).'/';

        try {
            foreach (Registry::get()->testSuite() as $suite) {
                foreach ($suite->directories() as $directory) {
                    $path = rtrim(realpath($directory->path()) ?: $directory->path(), '/');

                    if (str_starts_with($path.'/', $root)) {
                        $directories[] = substr($path, strlen($root));
                    }
                }
            }
        } catch (Throwable) {
            // Outside a PHPUnit run, as in a resolver's own tests.
        }

        return $directories;
    }

    /**
     * The psr-4, psr-0, classmap and files entries of autoload-dev in the
     * project's composer.json.
     *
     * @return list<string>
     */
    private static function autoloadDevPaths(string $projectRoot): array
    {
        $composer = json_decode((string) @file_get_contents($projectRoot.'/composer.json'), true);
        $autoload = is_array($composer) && is_array($composer['autoload-dev'] ?? null) ? $composer['autoload-dev'] : [];
        $paths = [];

        foreach (['psr-4', 'psr-0', 'classmap', 'files'] as $type) {
            foreach ((array) ($autoload[$type] ?? []) as $entry) {
                foreach ((array) $entry as $path) {
                    if (is_string($path)) {
                        $paths[] = $path;
                    }
                }
            }
        }

        return $paths;
    }
}
