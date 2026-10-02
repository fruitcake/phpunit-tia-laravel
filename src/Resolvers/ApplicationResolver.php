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

    /** @var array<string, list<string>> project root => test directories */
    private array $resolvedTestDirectories = [];

    /**
     * @param  list<string>  $paths  Project-relative fnmatch() patterns that
     *                               run every test. Add files of your own
     *                               that tests read, such as a script a
     *                               test runs.
     * @param  list<string>|null  $testDirectories  Where the tests are, null
     *                                              for the directories of the
     *                                              test suites in phpunit.xml.
     *                                              Any other file there, such
     *                                              as a base TestCase, a trait
     *                                              or a fixture, runs every
     *                                              test too.
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
        // Every test the graph knows: one it does not know runs anyway.
        // Graph::allTestFiles() predates its place on the Edges contract.
        if (! method_exists($edges, 'allTestFiles') || ! $this->runsEverything(rtrim($projectRoot, '/'), $changedRelativePath)) {
            return null;
        }

        return $edges->allTestFiles();
    }

    private function runsEverything(string $projectRoot, string $path): bool
    {
        foreach ($this->paths as $pattern) {
            if (fnmatch($pattern, $path)) {
                return true;
            }
        }

        // phpunit-tia never hands a resolver a test file, so anything that
        // reaches here from a test directory is something tests share.
        foreach ($this->resolvedTestDirectories[$projectRoot] ??= $this->testDirectories($projectRoot) as $directory) {
            if (str_starts_with($path, $directory.'/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string> project-relative directories
     */
    private function testDirectories(string $projectRoot): array
    {
        if ($this->testDirectories !== null) {
            return array_map(static fn (string $directory): string => rtrim($directory, '/'), $this->testDirectories);
        }

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

        return $directories === [] ? ['tests'] : array_values(array_unique($directories));
    }
}
