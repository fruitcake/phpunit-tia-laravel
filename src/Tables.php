<?php

declare(strict_types=1);

namespace Fruitcake\PhpUnitTia\Laravel;

/**
 * Table names in SQL, ported from Pest's TableExtractor. Regex rather than a
 * parser: what a query reads or writes is only used to link a test to more
 * migrations, never to skip one.
 */
final class Tables
{
    private const array DML_PREFIXES = ['select', 'insert', 'update', 'delete', 'with', 'replace'];

    private const string IDENTIFIER = '(?:"[^"]+"|`[^`]+`|\[[^\]]+\]|\w+)';

    private const string QUALIFIED = '('.self::IDENTIFIER.'(?:\s*\.\s*'.self::IDENTIFIER.')*)';

    /**
     * @return list<string> sorted table names a DML statement reads or writes
     */
    public static function fromSql(string $sql): array
    {
        $trimmed = ltrim($sql);

        if (preg_match('/^[a-zA-Z]+/', $trimmed, $prefix) !== 1) {
            return [];
        }

        if (! in_array(strtolower($prefix[0]), self::DML_PREFIXES, true)) {
            return [];
        }

        preg_match_all('/\b(?:from|into|update|join)\s+'.self::QUALIFIED.'/i', $sql, $matches);

        return self::names($matches[1]);
    }

    /** `CREATE TABLE new` */
    private const string CREATE = '/\bCREATE\s+(?:TEMPORARY\s+)?TABLE(?:\s+IF\s+NOT\s+EXISTS)?\s+'.self::QUALIFIED.'/i';

    /** `RENAME TABLE old TO new`, `ALTER TABLE old RENAME TO new` */
    private const string RENAME = '/\b(?:RENAME|ALTER)\s+TABLE\s+'.self::QUALIFIED.'\s+(?:RENAME\s+)?TO\s+'.self::QUALIFIED.'/i';

    /** Any other statement that changes, drops, writes or reads a table. */
    private const array TOUCH = [
        '/\b(?:ALTER|DROP|TRUNCATE)\s+TABLE(?:\s+IF\s+EXISTS)?\s+'.self::QUALIFIED.'/i',
        '/\b(?:INSERT|REPLACE)\s+(?:IGNORE\s+)?INTO\s+'.self::QUALIFIED.'/i',
        '/\bUPDATE\s+'.self::QUALIFIED.'\s+SET\b/i',
        '/\b(?:FROM|JOIN)\s+'.self::QUALIFIED.'/i',
    ];

    /**
     * Tables a statement in a migration creates, changes, drops or writes:
     * the SQL of `DB::statement()`, `DB::unprepared()` and the like.
     *
     * @return list<string> sorted table names
     */
    public static function fromStatement(string $sql): array
    {
        $names = [];

        foreach ([self::CREATE, self::RENAME, ...self::TOUCH] as $pattern) {
            preg_match_all($pattern, $sql, $matches);

            foreach (array_slice($matches, 1) as $group) {
                $names = [...$names, ...$group];
            }
        }

        return self::names($names);
    }

    /**
     * Tables a `CREATE TABLE` or rename in $sql brings into existence.
     *
     * @return list<string>
     */
    public static function createdByStatement(string $sql): array
    {
        preg_match_all(self::CREATE, $sql, $created);
        preg_match_all(self::RENAME, $sql, $renamed);

        return self::names([...$created[1], ...$renamed[2]]);
    }

    /**
     * `public.users`, `"analytics"."events"` => `users`, `events`
     */
    public static function name(string $qualified): string
    {
        $name = '';

        foreach (explode('.', $qualified) as $segment) {
            $segment = trim($segment, " \t\n\r\"`[]");

            if ($segment === '') {
                continue;
            }

            if (self::isSchemaMeta($segment)) {
                return '';
            }

            $name = $segment;
        }

        return strtolower($name);
    }

    /**
     * @param  array<int, string>  $qualified
     * @return list<string>
     */
    private static function names(array $qualified): array
    {
        $tables = [];

        foreach ($qualified as $name) {
            $name = self::name($name);

            if ($name !== '') {
                $tables[$name] = true;
            }
        }

        $out = array_map(strval(...), array_keys($tables));
        sort($out);

        return $out;
    }

    private static function isSchemaMeta(string $name): bool
    {
        $lower = strtolower($name);

        return in_array($lower, ['sqlite_master', 'sqlite_sequence', 'sqlite_schema', 'migrations'], true)
            || str_starts_with($lower, 'pg_')
            || str_starts_with($lower, 'information_schema');
    }
}
