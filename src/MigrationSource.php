<?php

declare(strict_types=1);

namespace Fruitcake\PhpUnitTia\Laravel;

use PhpToken;

/**
 * The tables a migration touches, read from its source.
 *
 * $tables is everything that could be read, which is enough to link a test
 * to more migrations. $complete says whether that is all of it: every
 * Schema:: and DB:: call named its table as a literal, and nothing else in
 * the migration reaches the database in a way this cannot follow (a model, a
 * `Schema::connection()`, a `$this->table`, `app('db')`). Only a complete
 * read may decide that a new migration affects no other test.
 */
final class MigrationSource
{
    /**
     * @param  list<string>  $tables
     * @param  list<string>  $created
     */
    public function __construct(
        public readonly array $tables,
        public readonly array $created,
        public readonly bool $complete,
    ) {}

    public static function parse(string $php): self
    {
        return (new MigrationReader(self::withoutComments($php)))->read();
    }

    private static function withoutComments(string $php): string
    {
        $tokens = PhpToken::tokenize(str_contains($php, '<?php') ? $php : "<?php\n".$php);
        $code = '';

        foreach ($tokens as $token) {
            if (! $token->is([T_COMMENT, T_DOC_COMMENT])) {
                $code .= $token->text;
            }
        }

        return $code;
    }
}
