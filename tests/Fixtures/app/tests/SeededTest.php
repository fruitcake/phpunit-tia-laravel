<?php

namespace Tests;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;

/**
 * Touches `users` only in setUp(), the way a seeder or factory would.
 */
final class SeededTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        DB::table('users')->insert(['name' => 'Seeded']);
    }

    #[Test]
    public function it_has_a_seeded_user(): void
    {
        $this->assertSame(1, DB::table('users')->where('name', 'Seeded')->count());
    }
}
