<?php

declare(strict_types=1);

namespace Fruitcake\PhpUnitTia\Laravel\Resolvers;

use Fruitcake\PhpUnitTia\Laravel\ClassReferences;
use JMac\Testing\PhpUnit\Tia\Contracts\EdgeAwareResolver;
use JMac\Testing\PhpUnit\Tia\Contracts\Edges;

/**
 * A PHP class no test is linked to, such as an enum with only cases, an
 * interface or a class with only constants: line coverage never links a
 * file without executable lines. It runs the tests linked to the files that
 * name it (ClassReferences), and the tests that name it themselves.
 *
 * A file that names it but is not linked either, such as another enum, is
 * followed the same way, a few levels deep. A file every test reads, such
 * as config, runs every test, as ApplicationResolver decides.
 *
 * A file that declares no class, or a class nothing leads to a test from,
 * such as a brand-new one, is left to the next resolver (null).
 */
final class ClassResolver implements EdgeAwareResolver
{
    private const int DEPTH = 3;

    /** @var array<string, ClassReferences> project root => references */
    private array $references = [];

    /**
     * @param  ApplicationResolver|null  $application  decides which
     *                                                 referencing files run
     *                                                 every test
     */
    public function __construct(
        private readonly ?ApplicationResolver $application = new ApplicationResolver,
    ) {}

    /**
     * @return list<string>|null
     */
    public function resolve(Edges $edges, string $projectRoot, string $changedRelativePath): ?array
    {
        $projectRoot = rtrim($projectRoot, '/');
        $path = $projectRoot.'/'.$changedRelativePath;

        if (! str_ends_with($changedRelativePath, '.php') || str_ends_with($changedRelativePath, '.blade.php') || ! is_file($path)) {
            return null;
        }

        $class = ClassReferences::declaredIn((string) file_get_contents($path));

        if ($class === null) {
            return null;
        }

        $references = $this->references[$projectRoot] ??= new ClassReferences($projectRoot, (new TestCode($projectRoot))->paths);
        $testFiles = array_flip($edges->allTestFiles());
        $tests = [];
        $visited = [$changedRelativePath => true];
        $queue = [[$class, 0]];

        while (($item = array_shift($queue)) !== null) {
            [$name, $depth] = $item;

            foreach ($references->using($name) as $file) {
                if (isset($visited[$file])) {
                    continue;
                }

                $visited[$file] = true;
                $found = $this->testsFor($edges, $projectRoot, $file, $testFiles);

                foreach ($found as $test) {
                    $tests[$test] = true;
                }

                if ($found !== [] || $depth + 1 >= self::DEPTH) {
                    continue;
                }

                $next = ClassReferences::declaredIn((string) $references->source($file));

                if ($next !== null) {
                    $queue[] = [$next, $depth + 1];
                }
            }
        }

        return $tests === [] ? null : array_map(strval(...), array_keys($tests));
    }

    /**
     * @param  array<string, int>  $testFiles
     * @return list<string>
     */
    private function testsFor(Edges $edges, string $projectRoot, string $file, array $testFiles): array
    {
        if (isset($testFiles[$file])) {
            return [$file];
        }

        $linked = $edges->testsLinkedTo($file);

        if ($linked !== []) {
            return $linked;
        }

        return array_values($this->application?->resolve($edges, $projectRoot, $file) ?? []);
    }
}
