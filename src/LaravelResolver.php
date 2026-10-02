<?php

declare(strict_types=1);

namespace Fruitcake\PhpUnitTia\Laravel;

use Fruitcake\PhpUnitTia\Laravel\Resolvers\ApplicationResolver;
use Fruitcake\PhpUnitTia\Laravel\Resolvers\MigrationResolver;
use Fruitcake\PhpUnitTia\Laravel\Resolvers\ViewResolver;
use JMac\Testing\PhpUnit\Tia\Contracts\EdgeAwareResolver;
use JMac\Testing\PhpUnit\Tia\Contracts\Edges;

/**
 * ApplicationResolver, MigrationResolver and ViewResolver in one, for the
 * common setup. Register them separately in phpunit-tia.php to leave one out
 * or to put a resolver of your own in between.
 *
 * ApplicationResolver goes first: a file next to the tests runs every test,
 * even when it looks like a migration or a view.
 */
final class LaravelResolver implements EdgeAwareResolver
{
    /** @var list<EdgeAwareResolver> */
    private readonly array $resolvers;

    /**
     * @param  list<string>  $migrationPaths  see MigrationResolver
     * @param  list<string>  $applicationPaths  see ApplicationResolver
     */
    public function __construct(
        array $migrationPaths = ['database/migrations'],
        array $applicationPaths = ApplicationResolver::PATHS,
    ) {
        $this->resolvers = [new ApplicationResolver($applicationPaths), new MigrationResolver($migrationPaths), new ViewResolver];
    }

    /**
     * @return list<string>|null
     */
    public function resolve(Edges $edges, string $projectRoot, string $changedRelativePath): ?array
    {
        foreach ($this->resolvers as $resolver) {
            $tests = $resolver->resolve($edges, $projectRoot, $changedRelativePath);

            if ($tests !== null) {
                return $tests;
            }
        }

        return null;
    }
}
