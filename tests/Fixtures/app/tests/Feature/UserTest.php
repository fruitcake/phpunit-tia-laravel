<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class UserTest extends TestCase
{
    #[Test]
    public function it_lists_users(): void
    {
        DB::table('users')->insert(['name' => 'Taylor']);

        $this->assertStringContainsString('avatar', view('users.index')->render());
        $this->assertSame(1, DB::table('users')->count());
    }
}
