<?php

namespace Tests;

use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;

final class LivewireTest extends TestCase
{
    #[Test]
    public function it_counts(): void
    {
        Livewire::test('counter')->call('increment')->assertSee('Count: 1');
    }

    #[Test]
    public function it_steps(): void
    {
        Livewire::test('stepper')->assertSee('Step 1');
    }
}
