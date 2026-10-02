<?php

declare(strict_types=1);

namespace Fruitcake\PhpUnitTia\Laravel\Tests\Feature;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;

final class QueriesCollectorTest extends TestCase
{
    #[Test]
    public function it_links_the_migrations_of_every_table_a_test_queries(): void
    {
        DB::table('invoices')->insert(['total' => 10]);

        $this->assertLinked(['database/migrations/2024_01_02_000000_create_invoices_table.php']);
        // The payments migration of a loadMigrationsFrom() path references invoices.
        $this->assertContains((string) realpath(self::MODULE_MIGRATIONS.'/2024_01_03_000000_create_payments_table.php'), $this->linked());
        $this->assertNotContains(self::app('database/migrations/2024_01_01_000000_create_users_table.php'), $this->linked());
    }

    #[Test]
    public function it_links_nothing_for_queries_on_tables_without_migrations(): void
    {
        DB::select('select * from sqlite_master');
        DB::select('select 1');

        $this->assertSame([], $this->linked());
    }
}
