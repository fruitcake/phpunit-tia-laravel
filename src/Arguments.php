<?php

declare(strict_types=1);

namespace Fruitcake\PhpUnitTia\Laravel;

/**
 * Reads the arguments of a call in PHP or Blade source, and whether each one
 * is a plain string literal. Enough to tell `Schema::table('users')` from
 * `Schema::table($this->table)` without a full parser.
 */
final class Arguments
{
    /**
     * @param  int  $open  offset of the `(` or `[` that opens the list
     * @return array{0: list<string>, 1: int}|null the trimmed arguments and the
     *                                             offset after the closing
     *                                             bracket, or null when the list
     *                                             is not closed
     */
    public static function at(string $code, int $open): ?array
    {
        if (! in_array($code[$open] ?? '', ['(', '['], true)) {
            return null;
        }

        $length = strlen($code);
        $depth = 0;
        $arguments = [];
        $current = '';

        for ($i = $open; $i < $length; $i++) {
            $char = $code[$i];

            if ($char === "'" || $char === '"' || ($char === '<' && substr($code, $i, 3) === '<<<')) {
                $end = self::stringEnd($code, $i);

                if ($end === null) {
                    return null;
                }

                $current .= substr($code, $i, $end - $i);
                $i = $end - 1;

                continue;
            }

            if ($char === '(' || $char === '[' || $char === '{') {
                if ($depth++ === 0) {
                    continue;
                }
            } elseif ($char === ')' || $char === ']' || $char === '}') {
                if (--$depth === 0) {
                    if (trim($current) !== '') {
                        $arguments[] = trim($current);
                    }

                    return [$arguments, $i + 1];
                }
            } elseif ($char === ',' && $depth === 1) {
                $arguments[] = trim($current);
                $current = '';

                continue;
            }

            $current .= $char;
        }

        return null;
    }

    /**
     * The argument passed as $name, or else at $position when that one is
     * not named: `constrained('users')` and `constrained(table: 'users')`
     * both find 'users', `constrained(column: 'uuid')` finds nothing.
     *
     * @param  list<string>  $arguments
     */
    public static function find(array $arguments, int $position, string $name): ?string
    {
        foreach ($arguments as $argument) {
            if (preg_match('/^(\w+)\s*:(?!:)\s*(.*)$/s', $argument, $named) === 1 && $named[1] === $name) {
                return $named[2];
            }
        }

        $argument = $arguments[$position] ?? null;

        return $argument === null || preg_match('/^\w+\s*:(?!:)/', $argument) === 1 ? null : $argument;
    }

    /**
     * The value of a string literal, or null for anything else: a variable,
     * a concatenation, a double-quoted string or heredoc that interpolates.
     */
    public static function literal(string $argument): ?string
    {
        $argument = trim($argument);

        if (preg_match('/^\'((?:[^\'\\\\]|\\\\.)*)\'$/s', $argument, $match) === 1) {
            return strtr($match[1], ["\\'" => "'", '\\\\' => '\\']);
        }

        if (preg_match('/^"((?:[^"\\\\$]|\\\\.)*)"$/s', $argument, $match) === 1) {
            return stripcslashes($match[1]);
        }

        if (preg_match('/^<<<[ \t]*(\'?)(\w+)\1\r?\n(.*?)\r?\n[ \t]*\2$/s', $argument, $match) === 1) {
            return $match[1] === "'" || ! str_contains($match[3], '$') ? $match[3] : null;
        }

        return null;
    }

    /**
     * Offset just past the string literal (quoted, heredoc or nowdoc) that
     * starts at $start.
     */
    private static function stringEnd(string $code, int $start): ?int
    {
        $quote = $code[$start];

        if ($quote === "'" || $quote === '"') {
            $length = strlen($code);

            for ($i = $start + 1; $i < $length; $i++) {
                if ($code[$i] === '\\') {
                    $i++;
                } elseif ($code[$i] === $quote) {
                    return $i + 1;
                }
            }

            return null;
        }

        if (preg_match('/\G<<<[ \t]*([\'"]?)(\w+)\1\r?\n/', $code, $match, 0, $start) !== 1) {
            return $start + 3;
        }

        if (preg_match('/\r?\n[ \t]*'.$match[2].'\b/', $code, $end, PREG_OFFSET_CAPTURE, $start + strlen($match[0]) - 1) !== 1) {
            return null;
        }

        return $end[0][1] + strlen($end[0][0]);
    }
}
