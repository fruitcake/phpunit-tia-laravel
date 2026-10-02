<?php

declare(strict_types=1);

namespace Fruitcake\PhpUnitTia\Laravel\Tests\Feature;

use Livewire\Livewire;
use Livewire\LivewireManager;
use PHPUnit\Framework\Attributes\RequiresMethod;
use PHPUnit\Framework\Attributes\Test;

#[RequiresMethod(LivewireManager::class, 'test')]
final class LivewireCollectorTest extends TestCase
{
    #[Test]
    public function it_links_the_source_of_a_single_file_component(): void
    {
        Livewire::test('counter')->call('increment')->assertSee('Count: 1');

        $this->assertLinked(['resources/views/components/⚡counter.blade.php']);
    }

    #[Test]
    public function it_links_every_file_of_a_multi_file_component(): void
    {
        Livewire::test('stepper')->assertSee('Step 1');

        $this->assertLinked([
            'resources/views/components/⚡stepper/stepper.php',
            'resources/views/components/⚡stepper/stepper.blade.php',
            'resources/views/components/⚡stepper/stepper.js',
        ]);
    }

    #[Test]
    public function a_component_rendered_inside_a_view_is_linked_too(): void
    {
        $this->app['view']->addLocation((string) realpath(__DIR__.'/../Fixtures/views'));

        view('with-livewire')->render();

        $this->assertLinked(['resources/views/components/⚡counter.blade.php']);
    }
}
