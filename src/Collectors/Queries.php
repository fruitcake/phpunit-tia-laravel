<?php

declare(strict_types=1);

namespace Fruitcake\PhpUnitTia\Laravel\Collectors;

use Fruitcake\PhpUnitTia\Laravel\Links;
use Fruitcake\PhpUnitTia\Laravel\Migrations;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Events\QueryExecuted;

/**
 * The migrations of every table a test queries. No line of a migration runs
 * inside a test, yet a migration that changes a table can break any test
 * using that table.
 */
final class Queries implements Collector
{
    private ?Migrations $migrations = null;

    public function register(Application $app, Links $links): void
    {
        $migrations = null;

        $app->make('events')->listen(QueryExecuted::class, function (QueryExecuted $query) use ($app, $links, &$migrations): void {
            $migrations ??= $this->migrations($app);

            $links->add(...$migrations->forSql($query->sql));
        });
    }

    /**
     * The directories the migrator runs, read on the first query of each
     * test. Each test creates a new application, but they rarely change, so
     * the migrations are only read again when they do.
     */
    private function migrations(Application $app): Migrations
    {
        $directories = [$app->databasePath('migrations')];

        if ($app->bound('migrator')) {
            $directories = [...$directories, ...$app->make('migrator')->paths()];
        }

        $directories = array_values(array_unique(array_map(
            static fn (string $directory): string => rtrim($directory, '/'),
            $directories,
        )));

        if ($this->migrations === null || $this->migrations->directories() !== $directories) {
            $this->migrations = new Migrations($directories);
        }

        return $this->migrations;
    }
}
