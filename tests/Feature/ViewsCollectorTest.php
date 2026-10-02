<?php

declare(strict_types=1);

namespace Fruitcake\PhpUnitTia\Laravel\Tests\Feature;

use Fruitcake\PhpUnitTia\Laravel\Collectors\Views;
use Fruitcake\PhpUnitTia\Laravel\Links;
use Livewire\Livewire;
use Livewire\LivewireManager;
use PHPUnit\Framework\Attributes\RequiresMethod;
use PHPUnit\Framework\Attributes\Test;

final class ViewsCollectorTest extends TestCase
{
    #[Test]
    public function it_links_every_view_a_test_renders(): void
    {
        view('invoices.show')->render();
        view('users.index')->render();

        $this->assertLinked([
            'resources/views/invoices/show.blade.php',
            'resources/views/partials/total.blade.php',
            'resources/views/users/index.blade.php',
            'resources/views/components/avatar.blade.php',
        ]);
    }

    #[Test]
    #[RequiresMethod(LivewireManager::class, 'test')]
    public function it_skips_generated_views(): void
    {
        Livewire::test('counter');

        $compiled = (string) realpath($this->app['config']->get('view.compiled'));

        foreach ($this->linked() as $file) {
            $this->assertStringStartsNotWith($compiled, $file);
        }
    }

    #[Test]
    public function it_waits_for_the_view_factory_to_be_resolved(): void
    {
        // Start from a factory nothing resolved yet.
        $concrete = $this->app->getBindings()['view']['concrete'];
        $this->app->offsetUnset('view');
        $this->app->singleton('view', $concrete);
        $links = new Links;

        (new Views)->register($this->app, $links);

        $this->assertFalse($this->app->resolved('view'), 'registering should not build the view factory');

        $this->app->make('view')->make('partials.total')->render();

        $this->assertSame([self::app('resources/views/partials/total.blade.php')], array_map(realpath(...), $links->all()));
    }
}
