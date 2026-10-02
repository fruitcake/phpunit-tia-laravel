<?php

declare(strict_types=1);

namespace Fruitcake\PhpUnitTia\Laravel\Resolvers;

use JMac\Testing\PhpUnit\Tia\Contracts\EdgeAwareResolver;
use JMac\Testing\PhpUnit\Tia\Contracts\Edges;

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

    /** @var array<string, TestCode> project root => its test code */
    private array $testCode = [];

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
        return ($this->testCode[$projectRoot] ??= new TestCode($projectRoot, $this->testDirectories))->contains($path);
    }
}
