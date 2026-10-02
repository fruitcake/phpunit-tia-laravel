<?php

declare(strict_types=1);

namespace Fruitcake\PhpUnitTia\Laravel\Tests\Unit;

use Fruitcake\PhpUnitTia\Laravel\Tables;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Cases ported from Pest's TableExtractor tests.
 */
final class TablesTest extends TestCase
{
    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function queries(): iterable
    {
        yield 'select' => ['select * from users', ['users']];
        yield 'insert' => ['INSERT INTO orders (id) VALUES (1)', ['orders']];
        yield 'update' => ['UPDATE posts SET title = ?', ['posts']];
        yield 'delete' => ['DELETE FROM sessions WHERE id = ?', ['sessions']];
        yield 'replace' => ['REPLACE INTO settings (key, value) VALUES (?, ?)', ['settings']];
        yield 'join' => ['select * from `orders` inner join `users` on `users`.`id` = `orders`.`user_id`', ['orders', 'users']];
        yield 'cte' => ['WITH recent AS (SELECT * FROM orders) SELECT * FROM recent JOIN users ON users.id = recent.user_id', ['orders', 'recent', 'users']];
        yield 'schema qualified' => ['select * from public.users', ['users']];
        yield 'quoted schema qualified' => ['select * from "public"."users"', ['users']];
        yield 'backticks' => ['select * from `analytics`.`events`', ['events']];
        yield 'brackets' => ['select * from [users]', ['users']];
        yield 'mixed case' => ['select * from Users', ['users']];
        yield 'subquery' => ['select * from users where id in (select user_id from invoices)', ['invoices', 'users']];
        yield 'sqlite metadata' => ["select * from sqlite_master where type = 'table'", []];
        yield 'postgres metadata' => ['select * from pg_catalog.pg_tables', []];
        yield 'information schema' => ['select * from information_schema.tables', []];
        yield 'migrations table' => ['select * from `migrations`', []];
        yield 'ddl' => ['alter table users add column age int', []];
        yield 'pragma' => ['PRAGMA foreign_keys = ON', []];
        yield 'empty' => ['   ', []];
    }

    /**
     * @param  list<string>  $expected
     */
    #[Test]
    #[DataProvider('queries')]
    public function it_reads_the_tables_a_query_reads_or_writes(string $sql, array $expected): void
    {
        $this->assertSame($expected, Tables::fromSql($sql));
    }

    #[Test]
    public function it_never_returns_integer_keys_for_numeric_identifiers(): void
    {
        $this->assertContainsOnlyString(Tables::fromSql('select substring(name from 1 for 3) from users'));
    }

    /**
     * @return iterable<string, array{string, list<string>, list<string>}>
     */
    public static function statements(): iterable
    {
        yield 'alter' => ['ALTER TABLE users ADD COLUMN age INT', ['users'], []];
        yield 'create if not exists' => ['CREATE TABLE IF NOT EXISTS invoices (id INT)', ['invoices'], ['invoices']];
        yield 'qualified create' => ['CREATE TABLE "analytics"."events" (id INT)', ['events'], ['events']];
        yield 'drop' => ['DROP TABLE IF EXISTS sessions', ['sessions'], []];
        yield 'truncate' => ['TRUNCATE TABLE jobs', ['jobs'], []];
        yield 'rename' => ['RENAME TABLE old_posts TO posts', ['old_posts', 'posts'], ['posts']];
        yield 'alter rename' => ['ALTER TABLE old_posts RENAME TO posts', ['old_posts', 'posts'], ['posts']];
        yield 'insert select' => ['INSERT INTO archive SELECT * FROM invoices', ['archive', 'invoices'], []];
        yield 'update' => ['UPDATE public.posts SET title = upper(title)', ['posts'], []];
        yield 'delete' => ['DELETE FROM `public`.`sessions`', ['sessions'], []];
        yield 'no table' => ['PRAGMA foreign_keys = OFF', [], []];
    }

    /**
     * @param  list<string>  $tables
     * @param  list<string>  $created
     */
    #[Test]
    #[DataProvider('statements')]
    public function it_reads_the_tables_a_migration_statement_touches(string $sql, array $tables, array $created): void
    {
        $this->assertSame($tables, Tables::fromStatement($sql));
        $this->assertSame($created, Tables::createdByStatement($sql));
    }
}
