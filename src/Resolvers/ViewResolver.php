<?php

declare(strict_types=1);

namespace Fruitcake\PhpUnitTia\Laravel\Resolvers;

use Fruitcake\PhpUnitTia\Laravel\ViewReferences;
use JMac\Testing\PhpUnit\Tia\Contracts\EdgeAwareResolver;
use JMac\Testing\PhpUnit\Tia\Contracts\Edges;

/**
 * A view no test rendered yet, such as a new partial, Blade component or
 * Livewire single-file component, runs the tests linked to the views and
 * classes that use it (ViewReferences).
 *
 * A view it finds no tests for, and any view at all while some view picks
 * what it renders at runtime, is left to the next resolver (null).
 */
final class ViewResolver implements EdgeAwareResolver
{
    /** @var array<string, ViewReferences> project root => views */
    private array $views = [];

    /**
     * @return list<string>|null
     */
    public function resolve(Edges $edges, string $projectRoot, string $changedRelativePath): ?array
    {
        $projectRoot = rtrim($projectRoot, '/');

        if (! ViewReferences::isView($changedRelativePath) || ! is_file($projectRoot.'/'.$changedRelativePath)) {
            return null;
        }

        $views = $this->views[$projectRoot] ??= new ViewReferences($projectRoot);

        if ($views->dynamicReferences() !== []) {
            return null;
        }

        $tests = LinkedTests::toAny($edges, $views->using($changedRelativePath));

        return $tests === [] ? null : $tests;
    }
}
