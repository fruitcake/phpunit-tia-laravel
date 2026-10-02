<?php

declare(strict_types=1);

namespace Fruitcake\PhpUnitTia\Laravel\Resolvers;

use JMac\Testing\PhpUnit\Tia\Contracts\EdgeAwareResolver;
use JMac\Testing\PhpUnit\Tia\Contracts\Edges;
use PHPUnit\TextUI\Configuration\Registry;
use Throwable;

/**
 * Files that every test can depend on, but that no test is ever linked to:
 * every test boots the application, which reads all config and route files,
 * and factories, seeders and the helpers next to the tests run outside
 * <source>. Line coverage records none of them, so a change to one runs
 * every test.
 *
 * Without this, such a change falls through to phpunit-tia's directory
 * fallback, which finds no test linked to its directory and runs nothing.
 */
final class ApplicationResolver implements EdgeAwareResolver
{
    /**
     * Matched with fnmatch(), where `*` also crosses directories.
     *
     * @var list<string>
     */
    public const array PATHS = [
        '.env.testing',
        'bootstrap/app.php',
        'bootstrap/providers.php',
        'config/*',
        'database/factories/*',
        'database/schema/*',
        'database/seeders/*',
        'lang/*',
        'resources/lang/*',
        'routes/*',
    ];

    /** @var array<string, list<string>> project root => test code paths */
    private array $sharedTestCode = [];

    /**
     * @param  list<string>  $paths  Project-relative fnmatch() patterns that
     *                               run every test. Add files of your own
     *                               that tests read, such as a script a
     *                               test runs.
     * @param  list<string>|null  $testDirectories  Where the tests are, null
     *                                              for the directories of the
     *                                              test suites in phpunit.xml.
     *                                              Any other file there or in
     *                                              composer.json's autoload-dev,
     *                                              such as a base TestCase, a
     *                                              trait or a fixture, runs
     *                                              every test too.
     */
    public function __construct(
        private readonly array $paths = self::PATHS,
        private readonly ?array $testDirectories = null,
    ) {}

    /**
     * @return list<string>|null
     */
    public function resolve(Edges $edges, string $projectRoot, string $changedRelativePath): ?array
    {
        if (! $this->runsEverything(rtrim($projectRoot, '/'), $changedRelativePath)) {
            return null;
        }

        // Every test the graph knows: one it does not know runs anyway.
        return array_values($edges->allTestFiles());
    }

    private function runsEverything(string $projectRoot, string $path): bool
    {
        foreach ($this->paths as $pattern) {
            if (fnmatch($pattern, $path)) {
                return true;
            }
        }

        // phpunit-tia never hands a resolver a test file, so anything that
        // reaches here from test code is something tests share.
        foreach ($this->sharedTestCode[$projectRoot] ??= $this->sharedTestCode($projectRoot) as $shared) {
            if ($path === $shared || str_starts_with($path, $shared.'/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * The test suite directories, and the autoload-dev paths of composer.json:
     * a base TestCase, its traits and helpers usually sit next to the suites
     * (tests/TestCase.php beside tests/Unit and tests/Feature), not in them.
     *
     * @return list<string> project-relative directories and files
     */
    private function sharedTestCode(string $projectRoot): array
    {
        $paths = [...($this->testDirectories ?? $this->suiteDirectories($projectRoot)), ...self::autoloadDevPaths($projectRoot)];
        $paths = array_values(array_unique(array_filter(array_map(
            static fn (string $path): string => trim((string) preg_replace('#^\./#', '', $path), '/'),
            $paths,
        ), static fn (string $path): bool => $path !== '' && $path !== '.')));

        return $paths === [] ? ['tests'] : $paths;
    }

    /**
     * @return list<string>
     */
    private function suiteDirectories(string $projectRoot): array
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
