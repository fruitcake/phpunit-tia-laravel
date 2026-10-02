<?php

declare(strict_types=1);

namespace Fruitcake\PhpUnitTia\Laravel;

use Illuminate\Support\Str;

/**
 * Reads one migration's source, without comments, into a MigrationSource.
 * Anything it cannot follow marks the read incomplete.
 *
 * @internal
 */
final class MigrationReader
{
    /** Schema:: methods whose first argument is the table. */
    private const array SCHEMA_TABLE_METHODS = [
        'create', 'table', 'drop', 'dropIfExists', 'dropColumns', 'hasTable', 'hasColumn', 'hasColumns',
        'hasIndex', 'getColumns', 'getColumnListing', 'getColumnType', 'getIndexes', 'getIndexListing',
        'getForeignKeys', 'whenTableHasColumn', 'whenTableDoesntHaveColumn',
    ];

    /** Schema:: methods that touch no table. */
    private const array SCHEMA_NEUTRAL_METHODS = [
        'disableForeignKeyConstraints', 'enableForeignKeyConstraints', 'withoutForeignKeyConstraints',
        'defaultStringLength', 'defaultMorphKeyType', 'morphUsingUuids', 'morphUsingUlids',
    ];

    /** DB:: methods whose first argument is SQL. */
    private const array DB_STATEMENT_METHODS = [
        'statement', 'unprepared', 'insert', 'update', 'delete', 'affectingStatement',
    ];

    /** DB:: methods that touch no table themselves. */
    private const array DB_NEUTRAL_METHODS = [
        'raw', 'transaction', 'beginTransaction', 'commit', 'rollBack', 'afterCommit', 'getDriverName',
        'getTablePrefix',
    ];

    /** Classes whose static calls and instances cannot reach the database. */
    private const array SAFE_CLASSES = [
        'self', 'static', 'parent', 'Str', 'Arr', 'Carbon', 'CarbonImmutable', 'Date', 'DateTime',
        'DateTimeImmutable', 'Hash', 'Crypt', 'Log', 'Collection', 'Expression',
    ];

    private const string SQL_STATEMENT = '/^\s*(?:CREATE|ALTER|DROP|TRUNCATE|RENAME|INSERT|REPLACE|UPDATE|DELETE|SELECT|WITH)\b/i';

    /** @var array<string, true> */
    private array $tables = [];

    /** @var array<string, true> */
    private array $created = [];

    private bool $complete = true;

    public function __construct(private readonly string $code) {}

    public function read(): MigrationSource
    {
        $this->staticCalls();
        $this->foreignKeys();
        $this->objectAccess();
        $this->rawSql();

        $tables = array_map(strval(...), array_keys($this->tables));
        $created = array_map(strval(...), array_keys($this->created));
        sort($tables);
        sort($created);

        return new MigrationSource($tables, $created, $this->complete && $tables !== []);
    }

    private function staticCalls(): void
    {
        preg_match_all('/(?<![\w$>\\\\])(?:\\\\?[A-Z]\w*\\\\)*([A-Z]\w*)\s*::\s*(\w+)\s*\(/', $this->code, $calls, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        foreach ($calls as $call) {
            $open = $call[0][1] + strlen($call[0][0]) - 1;

            match ($call[1][0]) {
                'Schema' => $this->schemaCall($call[2][0], $open),
                'DB' => $this->dbCall($call[2][0], $open),
                default => in_array($call[1][0], self::SAFE_CLASSES, true) || $this->unknown(),
            };
        }
    }

    private function schemaCall(string $method, int $open): void
    {
        $parsed = Arguments::at($this->code, $open);

        if ($parsed === null) {
            $this->unknown();

            return;
        }

        [$arguments, $end] = $parsed;

        if (in_array($method, self::SCHEMA_NEUTRAL_METHODS, true)) {
            return;
        }

        if ($method === 'connection' || $method === 'getConnection') {
            $this->onConnection($end, $this->schemaCall(...));

            return;
        }

        if ($method === 'rename') {
            $from = self::table(Arguments::find($arguments, 0, 'from'));
            $to = self::table(Arguments::find($arguments, 1, 'to'));

            if ($from === null || $to === null) {
                $this->unknown();

                return;
            }

            $this->touch($from);
            $this->create($to);

            return;
        }

        $table = self::table(Arguments::find($arguments, 0, 'table'));

        if ($table === null || ! in_array($method, self::SCHEMA_TABLE_METHODS, true)) {
            $this->unknown();

            return;
        }

        $method === 'create' ? $this->create($table) : $this->touch($table);
    }

    private function dbCall(string $method, int $open): void
    {
        $parsed = Arguments::at($this->code, $open);

        if ($parsed === null) {
            $this->unknown();

            return;
        }

        [$arguments, $end] = $parsed;

        if (in_array($method, self::DB_NEUTRAL_METHODS, true)) {
            return;
        }

        if ($method === 'connection') {
            $this->onConnection($end, $this->dbCall(...));

            return;
        }

        if ($method === 'table') {
            $table = self::table(Arguments::find($arguments, 0, 'table'));

            $table === null ? $this->unknown() : $this->touch($table);

            return;
        }

        $sql = in_array($method, self::DB_STATEMENT_METHODS, true)
            ? Arguments::literal(Arguments::find($arguments, 0, 'query') ?? '')
            : null;
        $tables = $sql === null ? [] : Tables::fromStatement($sql);

        if ($tables === []) {
            $this->unknown();

            return;
        }

        $this->touch(...$tables);
        $this->create(...Tables::createdByStatement((string) $sql));
    }

    /**
     * `DB::connection()->getDriverName()` touches no table. Anything else on
     * another connection is read for its tables, but cannot be complete: the
     * migrations of that connection's tables are not told apart.
     *
     * @param  callable(string, int): void  $call
     */
    private function onConnection(int $end, callable $call): void
    {
        if (preg_match('/\G\s*->\s*getDriverName\s*\(\s*\)/', $this->code, $match, 0, $end) === 1) {
            return;
        }

        if (preg_match('/\G\s*->\s*(\w+)\s*\(/', $this->code, $match, 0, $end) === 1) {
            $call($match[1], $end + strlen($match[0]) - 1);
        }

        $this->unknown();
    }

    /**
     * Tables a foreign key references: `->constrained('users')`,
     * `->references('id')->on('users')`, and `foreignId('user_id')->constrained()`,
     * which Laravel resolves to `users`. A `constrained()` whose table depends
     * on a model, as after `foreignIdFor(User::class)`, cannot be read.
     */
    private function foreignKeys(): void
    {
        preg_match_all('/->\s*(constrained|on)\s*\(/', $this->code, $calls, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        foreach ($calls as $call) {
            $parsed = Arguments::at($this->code, $call[0][1] + strlen($call[0][0]) - 1);
            $argument = $parsed === null ? null : Arguments::find($parsed[0], 0, 'table');

            $table = match (true) {
                $parsed === null => null,
                $argument !== null => self::table($argument),
                $call[1][0] === 'constrained' && self::referencesId($parsed[0]) => $this->implicitlyConstrained($call[0][1]),
                default => null,
            };

            $table === null ? $this->unknown() : $this->touch($table);
        }
    }

    /**
     * Whether `constrained()` keeps its default `id` column, the only one
     * Laravel can derive a table name for.
     *
     * @param  list<string>  $arguments
     */
    private static function referencesId(array $arguments): bool
    {
        $column = Arguments::find($arguments, 1, 'column');

        return $column === null || Arguments::literal($column) === 'id';
    }

    /**
     * The table `constrained()` without a table points to: the plural of
     * the column before `_id`, as long as the column was declared with a
     * literal `foreignId()`, `foreignUuid()` or `foreignUlid()` in the same
     * statement.
     */
    private function implicitlyConstrained(int $offset): ?string
    {
        $before = substr($this->code, 0, $offset);
        $start = max((int) strrpos($before, ';'), (int) strrpos($before, '{'));
        $statement = substr($before, $start);

        if (preg_match_all('/->\s*(foreignId|foreignUuid|foreignUlid|foreignIdFor)\s*\(/', $statement, $declarations, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) < 1) {
            return null;
        }

        $declaration = end($declarations);

        if ($declaration[1][0] === 'foreignIdFor') {
            return null;
        }

        $parsed = Arguments::at($statement, $declaration[0][1] + strlen($declaration[0][0]) - 1);
        $column = $parsed === null ? null : Arguments::literal(Arguments::find($parsed[0], 0, 'column') ?? '');

        if ($column === null || ! str_ends_with($column, '_id')) {
            return null;
        }

        return strtolower(Str::plural(substr($column, 0, -strlen('_id'))));
    }

    /**
     * Database access through an object rather than a facade: a model,
     * `app('db')`, an injected `$this->schema`.
     */
    private function objectAccess(): void
    {
        preg_match_all('/\bnew\s+(?!class\b)(?:\\\\?[A-Za-z_]\w*\\\\)*([A-Za-z_]\w*)/', $this->code, $instances);

        foreach ($instances[1] as $class) {
            if (! in_array($class, self::SAFE_CLASSES, true)) {
                $this->unknown();
            }
        }

        if (preg_match('/(?<![\w$>:\\\\])(?:app|resolve|db)\s*\(|->\s*(?:schema|db|getSchemaBuilder)\b/', $this->code) === 1) {
            $this->unknown();
        }
    }

    /**
     * SQL in a string the calls above did not read, such as a variable later
     * passed to DB::statement(). Only adds tables: that call itself already
     * made the read incomplete.
     */
    private function rawSql(): void
    {
        preg_match_all('/\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*"/s', $this->code, $literals);

        foreach ($literals[0] as $literal) {
            $sql = Arguments::literal($literal);

            if ($sql !== null && preg_match(self::SQL_STATEMENT, $sql) === 1) {
                $this->touch(...Tables::fromStatement($sql));
            }
        }
    }

    private function touch(string ...$tables): void
    {
        foreach ($tables as $table) {
            $this->tables[$table] = true;
        }
    }

    private function create(string ...$tables): void
    {
        foreach ($tables as $table) {
            $this->tables[$table] = true;
            $this->created[$table] = true;
        }
    }

    private function unknown(): bool
    {
        $this->complete = false;

        return false;
    }

    private static function table(?string $argument): ?string
    {
        $literal = $argument === null ? null : Arguments::literal($argument);
        $table = $literal === null ? '' : Tables::name($literal);

        return $table === '' ? null : $table;
    }
}
