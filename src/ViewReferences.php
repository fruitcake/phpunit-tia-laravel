<?php

declare(strict_types=1);

namespace Fruitcake\PhpUnitTia\Laravel;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Which files use a view, read from the source of resources/views, app/ and
 * routes/. For a view no test rendered yet, such as a new partial, these
 * lead to the tests that will: the tests linked to a view that includes it,
 * or to a class or route that renders it.
 *
 * Ported from Pest's Graph::bladeAncestorsFor(), with every template read
 * once into an index of who names what. Only literal names can be followed
 * (see BladeReferences); dynamicReferences() lists the views that pick one
 * at runtime.
 */
final class ViewReferences
{
    private const string VIEWS = 'resources/views/';

    private const string COMPONENTS = 'resources/views/components/';

    /** Livewire 4's default component locations => the namespace of their names. */
    private const array LIVEWIRE_LOCATIONS = [
        'resources/views/components/' => '',
        'resources/views/livewire/' => '',
        'resources/views/pages/' => 'pages::',
        'resources/views/layouts/' => 'layouts::',
    ];

    /** @var array<string, list<string>>|null `view:name`, `x:tag` or `livewire:name` => views that use it */
    private ?array $usedBy = null;

    /** @var list<string> */
    private array $dynamic = [];

    /** @var array<string, list<string>>|null string literal => PHP files that contain it */
    private ?array $literals = null;

    public function __construct(private readonly string $projectRoot) {}

    public static function isView(string $relativePath): bool
    {
        return str_starts_with($relativePath, self::VIEWS) && str_ends_with($relativePath, '.blade.php');
    }

    /**
     * @return list<string> project-relative views that name a view only known
     *                      at runtime
     */
    public function dynamicReferences(): array
    {
        $this->usedBy();

        return $this->dynamic;
    }

    /**
     * @return list<string> project-relative views that use $view, directly or
     *                      through another view, and the PHP files in app/ and
     *                      routes/ that name $view or one of those views
     */
    public function using(string $view): array
    {
        $usedBy = $this->usedBy();
        $found = [$view => true];
        $queue = [$view];

        while (($target = array_pop($queue)) !== null) {
            foreach (self::keys($target) as $key) {
                foreach ($usedBy[$key] ?? [] as $user) {
                    if (! isset($found[$user])) {
                        $found[$user] = true;
                        $queue[] = $user;
                    }
                }
            }
        }

        $literals = $this->literals();
        $files = [];

        foreach (array_keys($found) as $target) {
            foreach (self::phpNames($target) as $name) {
                foreach ($literals[$name] ?? [] as $file) {
                    $files[$file] = true;
                }
            }
        }

        unset($found[$view]);

        return [...array_map(strval(...), array_keys($found)), ...array_map(strval(...), array_keys($files))];
    }

    /**
     * The index keys a template uses $view by.
     *
     * @return list<string>
     */
    private static function keys(string $view): array
    {
        return [
            'view:'.self::viewName($view),
            ...array_map(static fn (string $tag): string => 'x:'.$tag, self::componentNames($view)),
            ...array_map(static fn (string $name): string => 'livewire:'.$name, self::livewireNames($view)),
        ];
    }

    /**
     * The strings PHP code names $view by. A bare Livewire name like 'orders'
     * is too common a string to count; a namespaced one like 'pages::orders'
     * is not.
     *
     * @return list<string>
     */
    private static function phpNames(string $view): array
    {
        return [
            self::viewName($view),
            ...array_filter(self::livewireNames($view), static fn (string $name): bool => str_contains($name, '::')),
        ];
    }

    /**
     * `resources/views/emails/invoice.blade.php` => `emails.invoice`
     */
    private static function viewName(string $view): string
    {
        return str_replace('/', '.', substr($view, strlen(self::VIEWS), -strlen('.blade.php')));
    }

    /**
     * The tags an anonymous component is used with: `<x-forms.text-input>` for
     * components/forms/text-input.blade.php, and `<x-card>` for
     * components/card/index.blade.php or components/card/card.blade.php as
     * well as `<x-card.index>` or `<x-card.card>`.
     *
     * @return list<string>
     */
    private static function componentNames(string $view): array
    {
        if (! str_starts_with($view, self::COMPONENTS)) {
            return [];
        }

        $segments = explode('/', strtolower(substr($view, strlen(self::COMPONENTS), -strlen('.blade.php'))));
        $names = self::shortened($segments, '');

        return array_values(array_unique([...$names, ...array_map(static fn (string $name): string => str_replace('_', '-', $name), $names)]));
    }

    /**
     * The names a Livewire 4 single-file component is used by: `orders` for
     * components/⚡orders.blade.php, `pages::orders` for
     * pages/⚡orders.blade.php, and `counter` for the multi-file
     * components/⚡counter/counter.blade.php or components/counter/index.blade.php.
     *
     * @return list<string>
     */
    private static function livewireNames(string $view): array
    {
        foreach (self::LIVEWIRE_LOCATIONS as $location => $namespace) {
            if (str_starts_with($view, $location)) {
                return self::shortened(array_map(
                    static fn (string $segment): string => strtolower((string) preg_replace('/^⚡[\x{FE0E}\x{FE0F}]?/u', '', $segment)),
                    explode('/', substr($view, strlen($location), -strlen('.blade.php'))),
                ), $namespace);
            }
        }

        return [];
    }

    /**
     * `card.index` and `card.card` are also used as `card`.
     *
     * @param  list<string>  $segments
     * @return list<string>
     */
    private static function shortened(array $segments, string $prefix): array
    {
        $names = [$prefix.implode('.', $segments)];
        $last = array_pop($segments);

        if ($segments !== [] && ($last === 'index' || $last === end($segments))) {
            $names[] = $prefix.implode('.', $segments);
        }

        return $names;
    }

    /**
     * @return array<string, list<string>>
     */
    private function usedBy(): array
    {
        if ($this->usedBy !== null) {
            return $this->usedBy;
        }

        $this->usedBy = [];

        foreach ($this->sources(self::VIEWS, '.blade.php') as $view => $source) {
            $references = BladeReferences::parse($source);

            if ($references['dynamic']) {
                $this->dynamic[] = $view;
            }

            foreach (['view' => $references['views'], 'x' => $references['components'], 'livewire' => $references['livewire']] as $kind => $names) {
                foreach ($names as $name) {
                    $this->usedBy[$kind.':'.$name][] = $view;
                }
            }
        }

        return $this->usedBy;
    }

    /**
     * @return array<string, list<string>>
     */
    private function literals(): array
    {
        if ($this->literals !== null) {
            return $this->literals;
        }

        $this->literals = [];

        foreach ([...$this->sources('app/', '.php'), ...$this->sources('routes/', '.php')] as $file => $source) {
            preg_match_all('/\'([^\'\\\\\n]{1,200})\'|"([^"\\\\\n$]{1,200})"/', $source, $matches);

            foreach (array_unique(array_filter([...$matches[1], ...$matches[2]])) as $literal) {
                $this->literals[$literal][] = $file;
            }
        }

        return $this->literals;
    }

    /**
     * @return array<string, string> project-relative path => source
     */
    private function sources(string $directory, string $suffix): array
    {
        $root = rtrim($this->projectRoot, '/').'/';

        if (! is_dir($root.$directory)) {
            return [];
        }

        $sources = [];
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root.$directory, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($files as $file) {
            assert($file instanceof SplFileInfo);

            if (! $file->isFile() || ! str_ends_with($file->getPathname(), $suffix)) {
                continue;
            }

            $source = @file_get_contents($file->getPathname());

            if ($source !== false) {
                $sources[substr($file->getPathname(), strlen($root))] = $source;
            }
        }

        ksort($sources);

        return $sources;
    }
}
