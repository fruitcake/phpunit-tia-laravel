<?php

declare(strict_types=1);

namespace Fruitcake\PhpUnitTia\Laravel;

/**
 * What one Blade template names: the views it includes, extends or renders,
 * the Blade and Livewire components it uses, and whether it names one by a
 * runtime value (`@include($partial)`), which could be any view.
 */
final class BladeReferences
{
    /** Directive => position of its view argument. */
    private const array DIRECTIVES = [
        'include' => 0,
        'includeIf' => 0,
        'includeWhen' => 1,
        'includeUnless' => 1,
        'includeFirst' => 0,
        'extends' => 0,
        'extendsFirst' => 0,
        'component' => 0,
        'each' => 0,
    ];

    /** @var array<string, true> */
    private array $views = [];

    /** @var array<string, true> */
    private array $components = [];

    /** @var array<string, true> */
    private array $livewire = [];

    private bool $dynamic = false;

    private function __construct(private readonly string $source) {}

    /**
     * Views by name (`partials.total`), Blade components by tag
     * (`forms.input`) and Livewire components by name (`pages::orders`).
     *
     * @return array{views: list<string>, components: list<string>, livewire: list<string>, dynamic: bool}
     */
    public static function parse(string $source): array
    {
        // Blade comments, @verbatim blocks and escaped @@directives are text.
        $source = (string) preg_replace(['/\{\{--.*?--\}\}/s', '/@verbatim\b.*?@endverbatim\b/s', '/@@\w+/'], '', $source);

        $references = new self($source);
        $references->directives();
        $references->viewCalls();
        $references->componentTags();
        $references->livewireTags();
        $references->livewireDirectives();

        return [
            'views' => array_map(strval(...), array_keys($references->views)),
            'components' => array_map(strval(...), array_keys($references->components)),
            'livewire' => array_map(strval(...), array_keys($references->livewire)),
            'dynamic' => $references->dynamic,
        ];
    }

    private function directives(): void
    {
        preg_match_all('/@('.implode('|', array_keys(self::DIRECTIVES)).')\b\s*(?=\()/', $this->source, $directives, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        foreach ($directives as [[$match, $offset], [$directive]]) {
            $parsed = Arguments::at($this->source, $offset + strlen($match));

            if ($parsed === null) {
                $this->dynamic = true;

                continue;
            }

            [$arguments] = $parsed;
            $argument = $arguments[self::DIRECTIVES[$directive]] ?? null;

            if ($directive === 'includeFirst' || $directive === 'extendsFirst') {
                $list = $argument === null ? null : Arguments::at(ltrim($argument), 0);

                foreach ($list === null ? [null] : $list[0] as $item) {
                    $this->view($item);
                }

                continue;
            }

            $this->view($argument);

            // @each('view', $items, 'item', 'empty') renders the fourth
            // argument when $items is empty.
            if ($directive === 'each' && isset($arguments[3])) {
                $this->view($arguments[3]);
            }
        }
    }

    private function viewCalls(): void
    {
        preg_match_all('/(?<![\w$>:])(?:view|View::make)\s*(?=\()/', $this->source, $calls, PREG_OFFSET_CAPTURE);

        foreach ($calls[0] as [$call, $offset]) {
            $parsed = Arguments::at($this->source, $offset + strlen($call));

            // view() without arguments returns the factory: `view()->exists(...)`.
            if ($parsed !== null && $parsed[0] === []) {
                continue;
            }

            $this->view($parsed[0][0] ?? null);
        }
    }

    private function componentTags(): void
    {
        // Possessive, so `<x-mail::button>` is skipped rather than read as `mai`.
        preg_match_all('/<x-([\w\-.]++)(?!::)/', $this->source, $tags, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        foreach ($tags as [[, $offset], [$tag]]) {
            $component = strtolower($tag);

            if ($component !== 'dynamic-component') {
                $this->components[$component] = true;

                continue;
            }

            // <x-dynamic-component component="alert"> is literal, the
            // :component="$name" form is not.
            $literal = $this->literalAttribute($offset, 'component');

            if ($literal === null) {
                $this->dynamic = true;
            } else {
                $this->components[$literal] = true;
            }
        }
    }

    private function livewireTags(): void
    {
        preg_match_all('/<livewire:([\w\-.]+(?:::[\w\-.]+)?)/', $this->source, $tags, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        foreach ($tags as [[, $offset], [$tag]]) {
            $component = strtolower($tag);

            if ($component === 'styles' || $component === 'scripts') {
                continue;
            }

            if ($component !== 'dynamic-component' && $component !== 'is') {
                $this->livewire[$component] = true;

                continue;
            }

            // <livewire:dynamic-component is="orders" /> is literal, the
            // :is="$name" and :component="$name" forms are not.
            $literal = $this->literalAttribute($offset, 'is') ?? $this->literalAttribute($offset, 'component');

            if ($literal === null) {
                $this->dynamic = true;
            } else {
                $this->livewire[$literal] = true;
            }
        }
    }

    private function livewireDirectives(): void
    {
        preg_match_all('/@livewire\s*(?=\()/', $this->source, $directives, PREG_OFFSET_CAPTURE);

        foreach ($directives[0] as [$directive, $offset]) {
            $parsed = Arguments::at($this->source, $offset + strlen($directive));
            $literal = $parsed === null ? null : Arguments::literal($parsed[0][0] ?? '');

            if ($literal === null) {
                $this->dynamic = true;
            } else {
                $this->livewire[strtolower($literal)] = true;
            }
        }
    }

    private function view(?string $argument): void
    {
        $literal = $argument === null ? null : Arguments::literal($argument);

        if ($literal === null) {
            $this->dynamic = true;
        } else {
            $this->views[$literal] = true;
        }
    }

    /**
     * The value of a plain `name="value"` attribute of the tag at $offset;
     * null for a bound `:name="$value"` one, or none at all.
     */
    private function literalAttribute(int $offset, string $name): ?string
    {
        $tag = substr($this->source, $offset, (int) strcspn($this->source, '>', $offset) + 1);

        return preg_match('/(?<![:\w-])'.$name.'="([\w\-.:]+)"/', $tag, $match) === 1 ? strtolower($match[1]) : null;
    }
}
