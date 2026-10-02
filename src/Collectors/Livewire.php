<?php

declare(strict_types=1);

namespace Fruitcake\PhpUnitTia\Laravel\Collectors;

use Fruitcake\PhpUnitTia\Laravel\Links;
use Illuminate\Contracts\Foundation\Application;
use Livewire\Component;
use Livewire\EventBus;

/**
 * The source of every Livewire 4 single-file or multi-file component a test
 * renders. Livewire runs a compiled copy from storage/, named after a hash,
 * so neither coverage nor the view composer sees the file you edit.
 *
 * Class components need nothing extra: coverage sees the class and the
 * Views collector sees its view.
 */
final class Livewire implements Collector
{
    public function register(Application $app, Links $links): void
    {
        if (! class_exists(EventBus::class) || ! $app->bound('livewire.finder')) {
            return;
        }

        // Per test, as the component locations are configuration.
        $sources = [];

        $app->make(EventBus::class)->on('render', static function (Component $component) use ($app, $links, &$sources): void {
            $name = $component->getName();

            if (is_string($name) && $name !== '') {
                $links->add(...$sources[$name] ??= self::sources($app, $name));
            }
        });
    }

    /**
     * @return list<string>
     */
    private static function sources(Application $app, string $name): array
    {
        $finder = $app->make('livewire.finder');

        $directory = $finder->resolveMultiFileComponentPath($name);

        if (is_string($directory) && is_dir($directory)) {
            return array_values(array_filter(glob($directory.'/*') ?: [], is_file(...)));
        }

        $file = $finder->resolveSingleFileComponentPath($name);

        return is_string($file) && is_file($file) ? [$file] : [];
    }
}
