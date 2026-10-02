<?php

namespace Tests;

use PHPUnit\Framework\Attributes\Test;

final class InertiaTest extends TestCase
{
    #[Test]
    public function it_renders_the_invoice_page(): void
    {
        $this->get('/invoices/1')->assertOk()->assertSee('"component":"Invoices', false);
    }
}
