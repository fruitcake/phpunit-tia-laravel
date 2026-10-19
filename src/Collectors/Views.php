<?php

declare(strict_types=1);

namespace Fruitcake\PhpUnitTia\Laravel\Collectors;

use Fruitcake\PhpUnitTia\Laravel\UsedFiles;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;

/**
 * Every view a test renders: pages, layouts, includes, Blade and Livewire
 * class component views, mail templates. Blade runs a compiled copy in
 * storage/, outside <source>, so coverage never sees them.
 */
final class Views implements Collector
{
    public function register(Application $app, UsedFiles $files): void
    {
        $compiled = rtrim((string) $app->make('config')->get('view.compiled'), '/');

        // A test that renders nothing should not pay for building the view
        // factory, so the composer waits until something resolves it.
        $listen = static function (Factory $factory) use ($files, $compiled): void {
            $factory->composer('*', static function (View $view) use ($files, $compiled): void {
                if (! method_exists($view, 'getPath')) {
                    return;
                }

                $path = (string) $view->getPath();

                // Generated views, such as a compiled Livewire component; the
                // Livewire collector links their source instead.
                if ($compiled === '' || ! str_starts_with($path, $compiled.'/')) {
                    $files->add($path);
                }
            });
        };

        if ($app->resolved('view')) {
            $listen($app->make('view'));
        } else {
            $app->afterResolving('view', $listen);
        }
    }
}
