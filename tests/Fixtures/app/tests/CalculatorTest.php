<?php

namespace Tests;

use App\Calculator;
use PHPUnit\Framework\Attributes\Test;

final class CalculatorTest extends TestCase
{
    #[Test]
    public function it_adds(): void
    {
        $this->assertSame(3, (new Calculator)->add(1, 2));
    }
}
