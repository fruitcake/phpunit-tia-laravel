<?php

declare(strict_types=1);

namespace Fruitcake\PhpUnitTia\Laravel\Tests\Unit;

use Fruitcake\PhpUnitTia\Laravel\Arguments;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ArgumentsTest extends TestCase
{
    #[Test]
    public function it_splits_top_level_arguments(): void
    {
        $code = "foo('a', fn () => bar(1, 2), ['x' => 'y, z'], \"q\")->baz()";

        $this->assertSame(
            [["'a'", 'fn () => bar(1, 2)', "['x' => 'y, z']", '"q"'], strlen("foo('a', fn () => bar(1, 2), ['x' => 'y, z'], \"q\")")],
            Arguments::at($code, 3),
        );
    }

    #[Test]
    public function it_reads_an_empty_list_and_a_trailing_comma(): void
    {
        $this->assertSame([[], 2], Arguments::at('()', 0));
        $this->assertSame([["'a'"], strlen("('a', )")], Arguments::at("('a', )", 0));
    }

    #[Test]
    public function it_ignores_brackets_inside_strings(): void
    {
        $this->assertSame([["')'", "'('"], 10], Arguments::at("(')', '(')", 0));
        $this->assertSame([["'it\\'s)'"], strlen("('it\\'s)')")], Arguments::at("('it\\'s)')", 0));
    }

    #[Test]
    public function it_skips_over_heredocs(): void
    {
        $code = "(<<<SQL\n    ALTER TABLE users ADD (age INT\n    SQL, 1)";

        $this->assertSame([["<<<SQL\n    ALTER TABLE users ADD (age INT\n    SQL", '1'], strlen($code)], Arguments::at($code, 0));
    }

    #[Test]
    public function it_returns_null_for_an_unclosed_list(): void
    {
        $this->assertNull(Arguments::at("('a', foo(", 0));
        $this->assertNull(Arguments::at('foo', 0));
    }

    #[Test]
    public function it_finds_an_argument_by_name_or_position(): void
    {
        $this->assertSame("'users'", Arguments::find(["'users'", "'id'"], 0, 'table'));
        $this->assertSame("'users'", Arguments::find(["column: 'id'", "table: 'users'"], 0, 'table'));
        $this->assertSame("'id'", Arguments::find(["'users'", "'id'"], 1, 'column'));
        $this->assertNull(Arguments::find(["column: 'uuid'"], 0, 'table'));
        $this->assertNull(Arguments::find([], 0, 'table'));
    }

    /**
     * @return iterable<string, array{string, ?string}>
     */
    public static function literals(): iterable
    {
        yield 'single quoted' => ["'users'", 'users'];
        yield 'escaped quote' => ["'it\\'s'", "it's"];
        yield 'double quoted' => ['"users"', 'users'];
        yield 'named argument' => ["table: 'users'", null];
        yield 'nowdoc' => ["<<<'SQL'\nALTER TABLE \$x\nSQL", 'ALTER TABLE $x'];
        yield 'heredoc without interpolation' => ["<<<SQL\n  DROP TABLE users\n  SQL", '  DROP TABLE users'];
        yield 'heredoc with interpolation' => ["<<<SQL\nDROP TABLE {\$table}\nSQL", null];
        yield 'interpolated string' => ['"{$table}"', null];
        yield 'variable' => ['$table', null];
        yield 'property' => ['$this->table', null];
        yield 'concatenation' => ["'prefix_'.\$name", null];
        yield 'class constant' => ['User::TABLE', null];
    }

    #[Test]
    #[DataProvider('literals')]
    public function it_reads_only_plain_string_literals(string $argument, ?string $expected): void
    {
        $this->assertSame($expected, Arguments::literal($argument));
    }
}
