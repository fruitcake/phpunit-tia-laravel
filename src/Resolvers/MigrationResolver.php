<?php

declare(strict_types=1);

namespace Fruitcake\PhpUnitTia\Laravel\Resolvers;

use Fruitcake\PhpUnitTia\Laravel\Migrations;
use Fruitcake\PhpUnitTia\Laravel\MigrationSource;
use JMac\Testing\PhpUnit\Tia\Contracts\EdgeAwareResolver;
use JMac\Testing\PhpUnit\Tia\Contracts\Edges;

/**
 * A new migration runs the tests linked to the earlier migrations of the
 * tables it touches. A table the migration creates has no earlier
 * migrations and no tests yet.
 *
 * A file counts as a migration when it is in one of $migrationPaths, or
 * anywhere else when it extends Migration. A migration it cannot read
 * completely, or one that alters a table without an earlier migration in
 * those paths or its own directory, is left to the next resolver (null).
 */
final class MigrationResolver implements EdgeAwareResolver
{
    /** @var array<string, list<string>> project root => directories */
    private array $directories = [];

    /** @var array<string, Migrations> directories => migrations */
    private array $migrations = [];

    /**
     * @param  list<string>  $migrationPaths  Directories that hold migrations,
     *                                        project-relative or absolute, glob
     *                                        patterns allowed. Add the paths
     *                                        given to loadMigrationsFrom() so a
     *                                        migration elsewhere finds the
     *                                        earlier migrations of its tables.
     */
    public function __construct(
        private readonly array $migrationPaths = ['database/migrations'],
    ) {}

    /**
     * @return list<string>|null
     */
    public function resolve(Edges $edges, string $projectRoot, string $changedRelativePath): ?array
    {
        $projectRoot = rtrim($projectRoot, '/');
        $path = $projectRoot.'/'.$changedRelativePath;

        if (! str_ends_with($changedRelativePath, '.php') || ! is_file($path)) {
            return null;
        }

        $source = (string) file_get_contents($path);
        $directories = $this->directories[$projectRoot] ??= $this->migrationDirectories($projectRoot);

        if (! in_array(dirname($path), $directories, true) && ! self::isMigration($source)) {
            return null;
        }

        $migration = MigrationSource::parse($source);

        if (! $migration->complete) {
            return null;
        }

        $migrations = $this->migrations(array_values(array_unique([...$directories, dirname($path)])));
        $earlier = [];

        foreach ($migration->tables as $table) {
            $others = array_values(array_diff($migrations->touching($table), [$path]));

            if ($others === [] && ! in_array($table, $migration->created, true)) {
                // Altered here, created somewhere this cannot see, such as a
                // package: no earlier migration does not mean no tests.
                return null;
            }

            $earlier = [...$earlier, ...$others];
        }

        return LinkedTests::toAny($edges, $earlier);
    }

    private static function isMigration(string $source): bool
    {
        return preg_match('/\bextends\s+\\\\?(?:Illuminate\\\\Database\\\\Migrations\\\\)?Migration\b/', $source) === 1;
    }

    /**
     * Every changed migration in one run shares the index of the same
     * directories, so they are read once.
     *
     * @param  list<string>  $directories
     */
    private function migrations(array $directories): Migrations
    {
        return $this->migrations[implode("\0", $directories)] ??= new Migrations($directories);
    }

    /**
     * @return list<string> absolute directories
     */
    private function migrationDirectories(string $projectRoot): array
    {
        $directories = [];

        foreach ($this->migrationPaths as $path) {
            $pattern = str_starts_with($path, '/') ? $path : $projectRoot.'/'.$path;

            foreach (glob(rtrim($pattern, '/'), GLOB_ONLYDIR) ?: [] as $directory) {
                $directories[] = $directory;
            }
        }

        return array_values(array_unique($directories));
    }
}
