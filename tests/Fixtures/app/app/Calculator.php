<?php

namespace App;

use App\Enums\Rounding;

final class Calculator
{
    public function add(int $a, int $b): int
    {
        return $a + $b;
    }

    public function rounding(): Rounding
    {
        return Rounding::Up;
    }
}
