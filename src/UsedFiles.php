<?php

declare(strict_types=1);

namespace Fruitcake\PhpUnitTia\Laravel;

/**
 * The files the running test used that its line coverage cannot show,
 * collected until the test is torn down.
 *
 * Handing them over before tearDown() would lose part of them: phpunit-tia
 * only accepts them once PHPUnit reports the test as prepared, which happens
 * after setUp(). Holding them here keeps what setUp() did too: a request, a
 * seeder, a factory.
 */
final class UsedFiles
{
    /** @var array<string, true> */
    private array $files = [];

    public function add(string ...$files): void
    {
        foreach ($files as $file) {
            if ($file !== '') {
                $this->files[$file] = true;
            }
        }
    }

    /**
     * @return list<string>
     */
    public function all(): array
    {
        return array_map(strval(...), array_keys($this->files));
    }
}
