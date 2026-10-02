<?php

declare(strict_types=1);

namespace Fruitcake\PhpUnitTia\Laravel\Tests\Unit;

use Fruitcake\PhpUnitTia\Laravel\Migrations;
use Fruitcake\PhpUnitTia\Laravel\Tests\Support\TemporaryProject;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MigrationsTest extends TestCase
{
    use TemporaryProject;

    protected function setUp(): void
    {
        $this->setUpTemporaryProject();

        $this->write('database/migrations/2024_01_01_create_invoices_table.php', "<?php Schema::create('invoices', fn () => null);\n");
        $this->write('database/migrations/2024_02_01_add_total_to_invoices.php', "<?php Schema::table('invoices', fn () => null);\n");
        $this->write('database/migrations/2024_01_01_create_users_table.php', "<?php Schema::create('users', fn () => null);\n");
        $this->write('database/migrations/2024_03_01_add_user_to_invoices.php', "<?php Schema::table('invoices', fn (\$t) => \$t->foreignId('user_id')->constrained());\n");
        $this->write('database/migrations/archive/2020_01_01_old_invoices.php', "<?php Schema::create('invoices', fn () => null);\n");
        $this->write('modules/billing/2024_03_01_add_vat_to_invoices.php', "<?php Schema::table('invoices', fn () => null);\n");
        $this->write('modules/billing/2024_04_01_partial.php', "<?php Schema::table('payments', fn () => null);\nSchema::table(\$this->table, fn () => null);\n");
    }

    protected function tearDown(): void
    {
        $this->tearDownTemporaryProject();
    }

    #[Test]
    public function it_finds_the_migrations_of_a_table_in_the_top_level_of_each_directory(): void
    {
        $migrations = $this->migrations();

        $this->assertSame([
            $this->root.'/database/migrations/2024_01_01_create_invoices_table.php',
            $this->root.'/database/migrations/2024_02_01_add_total_to_invoices.php',
            $this->root.'/database/migrations/2024_03_01_add_user_to_invoices.php',
            $this->root.'/modules/billing/2024_03_01_add_vat_to_invoices.php',
        ], $migrations->touching('INVOICES'));
    }

    #[Test]
    public function a_foreign_key_links_the_migration_to_the_referenced_table(): void
    {
        $this->assertSame([
            $this->root.'/database/migrations/2024_01_01_create_users_table.php',
            $this->root.'/database/migrations/2024_03_01_add_user_to_invoices.php',
        ], $this->migrations()->touchingAny(['users', 'sessions']));
    }

    #[Test]
    public function a_migration_it_cannot_read_completely_still_counts_for_the_tables_it_names(): void
    {
        $this->assertSame([$this->root.'/modules/billing/2024_04_01_partial.php'], $this->migrations()->touching('payments'));
    }

    #[Test]
    public function it_maps_a_query_to_the_migrations_of_its_tables(): void
    {
        $migrations = $this->migrations();
        $sql = 'select * from "users" inner join "sessions" on "sessions"."user_id" = "users"."id"';

        $this->assertSame([
            $this->root.'/database/migrations/2024_01_01_create_users_table.php',
            $this->root.'/database/migrations/2024_03_01_add_user_to_invoices.php',
        ], $migrations->forSql($sql));
        $this->assertSame($migrations->forSql($sql), $migrations->forSql($sql));
        $this->assertSame([], $migrations->forSql('select * from sqlite_master'));
    }

    private function migrations(): Migrations
    {
        return new Migrations([$this->root.'/database/migrations', $this->root.'/modules/billing']);
    }
}
