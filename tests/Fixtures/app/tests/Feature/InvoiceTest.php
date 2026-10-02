<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class InvoiceTest extends TestCase
{
    #[Test]
    public function it_shows_an_invoice(): void
    {
        $this->assertStringContainsString('Total', view('invoices.show')->render());
    }

    #[Test]
    public function it_stores_an_invoice(): void
    {
        DB::table('invoices')->insert(['total' => 10]);

        $this->assertSame(1, DB::table('invoices')->count());
    }
}
