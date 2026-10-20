<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class AdminTest extends TestCase
{
    #[Test]
    public function it_lists_invoices(): void
    {
        $this->get('/admin/invoices')->assertOk()->assertSee('Invoices');
    }
}
