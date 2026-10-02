<?php

declare(strict_types=1);

namespace Fruitcake\PhpUnitTia\Laravel;

/**
 * The migrations in a set of directories, by the tables they touch. Read
 * once and kept for the rest of the run.
 *
 * Laravel only runs the `*.php` files directly in a migration directory, so
 * subdirectories are left out here too.
 */
final class Migrations
{
    private const int SQL_CACHE_LIMIT = 5000;

    /** @var array<string, list<string>>|null table => migration files */
    private ?array $byTable = null;

    /** @var array<string, list<string>> SQL => migration files */
    private array $bySql = [];

    /**
     * @param  list<string>  $directories  Absolute paths.
     */
    public function __construct(private readonly array $directories) {}

    /**
     * @return list<string>
     */
    public function directories(): array
    {
        return $this->directories;
    }

    /**
     * @return list<string> absolute paths of the migrations that touch $table
     */
    public function touching(string $table): array
    {
        return $this->byTable()[strtolower($table)] ?? [];
    }

    /**
     * @param  list<string>  $tables
     * @return list<string> absolute paths of the migrations that touch any of $tables
     */
    public function touchingAny(array $tables): array
    {
        $files = [];

        foreach ($tables as $table) {
            foreach ($this->touching($table) as $file) {
                $files[$file] = true;
            }
        }

        return array_map(strval(...), array_keys($files));
    }

    /**
     * The migrations of the tables a query reads or writes. A test runs the
     * same few queries many times, so the answer is kept per SQL string.
     *
     * @return list<string>
     */
    public function forSql(string $sql): array
    {
        if (isset($this->bySql[$sql])) {
            return $this->bySql[$sql];
        }

        if (count($this->bySql) >= self::SQL_CACHE_LIMIT) {
            $this->bySql = [];
        }

        return $this->bySql[$sql] = $this->touchingAny(Tables::fromSql($sql));
    }

    /**
     * @return array<string, list<string>>
     */
    private function byTable(): array
    {
        if ($this->byTable !== null) {
            return $this->byTable;
        }

        $byTable = [];

        foreach ($this->directories as $directory) {
            $files = glob(rtrim($directory, '/').'/*.php') ?: [];
            sort($files);

            foreach ($files as $file) {
                $source = @file_get_contents($file);

                if ($source === false) {
                    continue;
                }

                foreach (MigrationSource::parse($source)->tables as $table) {
                    $byTable[$table][] = $file;
                }
            }
        }

        return $this->byTable = $byTable;
    }
}
