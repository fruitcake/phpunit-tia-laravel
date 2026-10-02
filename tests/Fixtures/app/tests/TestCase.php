<?php

namespace Tests;

use Fruitcake\PhpUnitTia\Laravel\RunWithLaravelTia;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\ServiceProvider as InertiaServiceProvider;
use Livewire\LivewireServiceProvider;
use Orchestra\Testbench\TestCase as Testbench;

abstract class TestCase extends Testbench
{
    use RefreshDatabase;
    use RunWithLaravelTia;

    protected function getPackageProviders($app): array
    {
        return [LivewireServiceProvider::class, InertiaServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $root = dirname(__DIR__);

        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('view.paths', [$root.'/resources/views']);
        $app['config']->set('view.compiled', $root.'/storage/views');
        $app['config']->set('livewire.component_locations', [$root.'/resources/views/components']);
        $app['config']->set('livewire.component_namespaces', ['pages' => $root.'/resources/views/pages']);
        $app['config']->set('inertia.pages.paths', [$root.'/resources/js/pages']);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(dirname(__DIR__).'/database/migrations');
    }

    protected function defineRoutes($router): void
    {
        Route::get('/invoices/{id}', fn () => Inertia::render('Invoices/Show'));
    }
}
