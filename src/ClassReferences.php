<?php

declare(strict_types=1);

namespace Fruitcake\PhpUnitTia\Laravel;

use PhpToken;

/**
 * Which files name a class, read from the source of app/, routes/, config/,
 * database/ and the test code. For a class line coverage never links, such
 * as an enum with only cases, these lead to the tests that depend on it.
 *
 * A file names a class through `use`, a group `use`, its fully qualified
 * name (in code, or as a string as config and the container use it), or its
 * short name when both share a namespace.
 */
final class ClassReferences
{
    private const array DIRECTORIES = ['app', 'routes', 'config', 'database'];

    /** @var array<string, string>|null project-relative path => source */
    private ?array $sources = null;

    /** @var array<string, string> project-relative path => lowercase namespace */
    private array $namespaces = [];

    /** @var array<string, list<string>> lowercase class => files that name it */
    private array $references = [];

    /**
     * @param  list<string>  $testCode  project-relative test directories and files
     */
    public function __construct(
        private readonly string $projectRoot,
        private readonly array $testCode = ['tests'],
    ) {}

    /**
     * The class, interface, trait or enum a file declares, with its
     * namespace, or null when it declares none.
     */
    public static function declaredIn(string $source): ?string
    {
        $namespace = '';
        $tokens = PhpToken::tokenize($source);
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if ($token->is(T_NAMESPACE)) {
                $name = self::next($tokens, $i);

                if ($name !== null && $name->is([T_STRING, T_NAME_QUALIFIED])) {
                    $namespace = $name->text;
                }

                continue;
            }

            if (! $token->is([T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM])) {
                continue;
            }

            // Not `new class`, `Foo::class` or an anonymous class argument.
            $previous = self::previous($tokens, $i);

            if ($previous !== null && $previous->is([T_NEW, T_DOUBLE_COLON])) {
                continue;
            }

            $name = self::next($tokens, $i);

            if ($name !== null && $name->is(T_STRING)) {
                return ltrim($namespace.'\\'.$name->text, '\\');
            }
        }

        return null;
    }

    /**
     * @return list<string> project-relative files that name $class
     */
    public function using(string $class): array
    {
        return $this->references[strtolower($class)] ??= $this->search($class);
    }

    public function source(string $file): ?string
    {
        return $this->sources()[$file] ?? null;
    }

    /**
     * @return list<string>
     */
    private function search(string $class): array
    {
        $segments = explode('\\', ltrim($class, '\\'));
        $short = (string) array_pop($segments);
        $namespace = strtolower(implode('\\', $segments));
        // App\Enums\Reason, \App\Enums\Reason, and 'App\\Enums\\Reason' in a string.
        $qualified = implode('\\\\{1,2}', array_map(static fn (string $segment): string => preg_quote($segment, '/'), [...$segments, $short]));
        $patterns = [
            '/(?<![\w\\\\])\\\\{0,2}'.$qualified.'(?![\w\\\\])/i',
            '/\buse\s+\\\\?'.implode('\\\\', array_map(static fn (string $segment): string => preg_quote($segment, '/'), $segments)).'\\\\\{[^}]*(?<![\w\\\\])'.preg_quote($short, '/').'\b/i',
        ];
        $sameNamespace = '/(?<![\w\\\\$>:])'.preg_quote($short, '/').'(?![\w\\\\])/';

        $files = [];

        foreach ($this->sources() as $file => $source) {
            if (stripos($source, $short) === false) {
                continue;
            }

            if (preg_match($patterns[0], $source) === 1 || ($segments !== [] && preg_match($patterns[1], $source) === 1)
                || ($this->namespaces[$file] === $namespace && preg_match($sameNamespace, $source) === 1)) {
                $files[] = $file;
            }
        }

        return $files;
    }

    /**
     * @return array<string, string>
     */
    private function sources(): array
    {
        if ($this->sources !== null) {
            return $this->sources;
        }

        $this->sources = [];

        foreach ([...self::DIRECTORIES, ...$this->testCode] as $path) {
            $this->sources += SourceFiles::in($this->projectRoot, $path, '.php');
        }

        foreach ($this->sources as $file => $source) {
            $this->namespaces[$file] = preg_match('/^\s*namespace\s+([\w\\\\]+)\s*;/m', $source, $match) === 1 ? strtolower($match[1]) : '';
        }

        return $this->sources;
    }

    /**
     * @param  list<PhpToken>  $tokens
     */
    private static function next(array $tokens, int $i): ?PhpToken
    {
        for ($j = $i + 1, $count = count($tokens); $j < $count; $j++) {
            if (! $tokens[$j]->isIgnorable()) {
                return $tokens[$j];
            }
        }

        return null;
    }

    /**
     * @param  list<PhpToken>  $tokens
     */
    private static function previous(array $tokens, int $i): ?PhpToken
    {
        for ($j = $i - 1; $j >= 0; $j--) {
            if (! $tokens[$j]->isIgnorable()) {
                return $tokens[$j];
            }
        }

        return null;
    }
}
