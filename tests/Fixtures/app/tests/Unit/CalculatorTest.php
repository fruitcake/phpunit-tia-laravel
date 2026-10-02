<?php

namespace Tests\Unit;

use App\Calculator;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class CalculatorTest extends TestCase
{
    #[Test]
    public function it_adds(): void
    {
        $this->assertSame(3, (new Calculator)->add(1, 2));
    }
}
