<?php

declare(strict_types=1);

namespace Fruitcake\PhpUnitTia\Laravel\Resolvers;

use JMac\Testing\PhpUnit\Tia\Contracts\Edges;

/**
 * @internal
 */
final class LinkedTests
{
    /**
     * @param  list<string>  $sourceFiles
     * @return list<string> the tests linked to any of $sourceFiles
     */
    public static function toAny(Edges $edges, array $sourceFiles): array
    {
        $tests = [];

        foreach ($sourceFiles as $sourceFile) {
            foreach ($edges->testsLinkedTo($sourceFile) as $test) {
                $tests[$test] = true;
            }
        }

        return array_map(strval(...), array_keys($tests));
    }
}
