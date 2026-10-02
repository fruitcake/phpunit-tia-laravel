<?php

declare(strict_types=1);

namespace Fruitcake\PhpUnitTia\Laravel;

/**
 * The files the running test used, collected until the test is torn down.
 *
 * phpunit-tia only accepts links once PHPUnit reports the test as prepared,
 * which happens after setUp(). Holding them here until tearDown() keeps what
 * setUp() did too: a request, a seeder, a factory.
 */
final class Links
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
