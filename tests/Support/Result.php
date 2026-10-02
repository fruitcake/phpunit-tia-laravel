<?php

declare(strict_types=1);

namespace Fruitcake\PhpUnitTia\Laravel\Tests\Support;

use SimpleXMLElement;

/**
 * Which tests of a fixture run actually ran and which TIA skipped, read
 * from PHPUnit's JUnit log.
 */
final class Result
{
    /**
     * @param  list<string>  $ran  `Class::method` of each test that ran
     * @param  list<string>  $skipped  `Class::method` of each skipped test
     */
    private function __construct(
        public readonly array $ran,
        public readonly array $skipped,
        public readonly string $output,
        public readonly int $exitCode,
    ) {}

    public static function fromJunit(string $xml, string $output, int $exitCode): self
    {
        $ran = [];
        $skipped = [];

        foreach ((new SimpleXMLElement($xml))->xpath('//testcase') ?: [] as $case) {
            $id = substr((string) $case['class'], strlen('Tests\\')).'::'.$case['name'];

            if (isset($case->skipped)) {
                $skipped[] = $id;
            } else {
                $ran[] = $id;
            }
        }

        sort($ran);
        sort($skipped);

        return new self($ran, $skipped, $output, $exitCode);
    }

    /**
     * @return list<string> the test classes with at least one test that ran
     */
    public function ranClasses(): array
    {
        $classes = array_values(array_unique(array_map(
            static fn (string $id): string => explode('::', $id)[0],
            $this->ran,
        )));

        sort($classes);

        return $classes;
    }
}
