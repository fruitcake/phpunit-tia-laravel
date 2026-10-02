<?php

declare(strict_types=1);

namespace Fruitcake\PhpUnitTia\Laravel\Tests\Unit;

use Fruitcake\PhpUnitTia\Laravel\LaravelResolver;
use Fruitcake\PhpUnitTia\Laravel\Resolvers\MigrationResolver;
use Fruitcake\PhpUnitTia\Laravel\Resolvers\ViewResolver;
use Fruitcake\PhpUnitTia\Laravel\Tests\Support\TemporaryProject;
use JMac\Testing\PhpUnit\Tia\Contracts\EdgeAwareResolver;
use JMac\Testing\PhpUnit\Tia\Contracts\Resolver;
use JMac\Testing\PhpUnit\Tia\Graph;
use JMac\Testing\PhpUnit\Tia\TestPaths;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Runs the resolver through core's Graph::affected(), with the edges
 * RecordsLaravelEdges would have recorded linked by hand, and a FullSuite
 * resolver after it to show when a path is left to the fallback.
 */
final class LaravelResolverTest extends TestCase
{
    use TemporaryProject;

    private const array EVERYTHING = ['tests/InvoiceTest.php', 'tests/ModuleTest.php', 'tests/UserTest.php'];

    protected function setUp(): void
    {
        $this->setUpTemporaryProject();

        $this->write('database/migrations/2024_01_01_create_users_table.php', self::migration("Schema::create('users', fn () => null);"));
        $this->write('database/migrations/2024_01_02_create_invoices_table.php', self::migration("Schema::create('invoices', fn () => null);"));
        $this->write('modules/Billing/migrations/2024_01_03_create_payments_table.php', self::migration("Schema::create('payments', fn () => null);"));
        $this->write('resources/views/invoices/show.blade.php', "@include('partials.total')\n@includeIf('partials.discount')\n");
        $this->write('resources/views/partials/total.blade.php', "<p></p>\n");
        $this->write('resources/views/users/index.blade.php', "<ul></ul>\n");
        $this->write('tests/InvoiceTest.php', "<?php\n");
        $this->write('tests/UserTest.php', "<?php\n");
        $this->write('tests/ModuleTest.php', "<?php\n");
    }

    protected function tearDown(): void
    {
        $this->tearDownTemporaryProject();
    }

    #[Test]
    public function a_new_migration_runs_the_tests_using_its_tables(): void
    {
        $this->write('database/migrations/2024_02_01_add_total_to_invoices.php', self::migration("Schema::table('invoices', fn () => null);"));

        $this->assertAffected(['tests/InvoiceTest.php'], 'database/migrations/2024_02_01_add_total_to_invoices.php');
    }

    #[Test]
    public function a_new_migration_runs_only_the_tests_that_query_its_table(): void
    {
        // `audits` is created by a migration that also alters `users`, so the
        // Queries collector linked every test that queries `users` to it.
        $this->write('database/migrations/2024_01_05_create_audits_table.php', self::migration("Schema::create('audits', fn () => null);\nSchema::table('users', fn () => null);"));
        $this->write('database/migrations/2024_01_06_add_note_to_audits.php', self::migration("Schema::table('audits', fn () => null);"));
        // Added after the baseline: no test is linked to it yet.
        $this->write('database/migrations/2024_01_07_add_index_to_audits.php', self::migration("Schema::table('audits', fn () => null);"));
        $this->write('database/migrations/2024_02_01_add_user_to_audits.php', self::migration("Schema::table('audits', fn () => null);"));
        $this->write('database/migrations/2024_02_02_add_audit_to_invoices.php', self::migration("Schema::table('audits', fn () => null);\nSchema::table('invoices', fn () => null);"));

        $audits = ['database/migrations/2024_01_05_create_audits_table.php', 'database/migrations/2024_01_06_add_note_to_audits.php'];
        $links = [
            'tests/UserTest.php' => ['database/migrations/2024_01_05_create_audits_table.php'],
            'tests/AuditTest.php' => $audits,
            'tests/AuditUserTest.php' => [...$audits, 'database/migrations/2024_01_01_create_users_table.php'],
        ];
        $affected = fn (string $changed): array => $this->sorted($this->graph(new LaravelResolver, $links)->affected([$changed]));

        $this->assertSame(['tests/AuditTest.php', 'tests/AuditUserTest.php'], $affected('database/migrations/2024_02_01_add_user_to_audits.php'));
        // Across tables, the tests of each.
        $this->assertSame(['tests/AuditTest.php', 'tests/AuditUserTest.php', 'tests/InvoiceTest.php'], $affected('database/migrations/2024_02_02_add_audit_to_invoices.php'));
    }

    #[Test]
    public function a_migration_of_a_new_table_runs_nothing(): void
    {
        $this->write('database/migrations/2024_02_01_create_audits_table.php', self::migration("Schema::create('audits', fn () => null);"));

        $this->assertAffected([], 'database/migrations/2024_02_01_create_audits_table.php');
    }

    #[Test]
    public function a_foreign_key_counts_as_touching_the_referenced_table(): void
    {
        $this->write('database/migrations/2024_02_01_create_audits_table.php', self::migration(
            "Schema::create('audits', function (\$table) {\n    \$table->foreignId('owner_id')->constrained('users');\n    \$table->foreign('invoice_id')->references('id')->on('invoices');\n});",
        ));

        $this->assertAffected(['tests/InvoiceTest.php', 'tests/UserTest.php'], 'database/migrations/2024_02_01_create_audits_table.php');
    }

    #[Test]
    public function a_migration_it_cannot_read_completely_is_left_to_the_next_resolver(): void
    {
        // Reading only `audits` would claim the path and run nothing.
        $this->write('database/migrations/2024_02_01_create_audits_table.php', self::migration(
            "Schema::create('audits', fn () => null);\nSchema::connection('legacy')->table('users', fn () => null);",
        ));
        $this->write('database/migrations/2024_02_02_alter_from_property.php', self::migration(
            "Schema::create('logs', fn () => null);\nSchema::table(\$this->table, fn () => null);",
        ));

        $this->assertAffected(self::EVERYTHING, 'database/migrations/2024_02_01_create_audits_table.php');
        $this->assertAffected(self::EVERYTHING, 'database/migrations/2024_02_02_alter_from_property.php');
    }

    #[Test]
    public function a_migration_without_readable_tables_is_left_to_the_next_resolver(): void
    {
        $this->write('database/migrations/2024_02_01_backfill.php', self::migration("Artisan::call('app:backfill');"));

        $this->assertAffected(self::EVERYTHING, 'database/migrations/2024_02_01_backfill.php');
    }

    #[Test]
    public function a_table_altered_without_any_earlier_migration_is_left_to_the_next_resolver(): void
    {
        // Created by a package or a directory nothing linked: no earlier
        // migration does not mean no tests.
        $this->write('database/migrations/2024_02_01_add_column_to_jobs.php', self::migration("Schema::table('jobs', fn () => null);"));

        $this->assertAffected(self::EVERYTHING, 'database/migrations/2024_02_01_add_column_to_jobs.php');
    }

    #[Test]
    public function it_reads_migrations_from_the_configured_paths(): void
    {
        $this->write('modules/Billing/migrations/2024_02_01_add_total_to_payments.php', self::migration("Schema::table('payments', fn () => null);"));

        $this->assertAffected(
            ['tests/ModuleTest.php'],
            'modules/Billing/migrations/2024_02_01_add_total_to_payments.php',
            new LaravelResolver(['database/migrations', 'modules/*/migrations']),
        );
    }

    #[Test]
    public function a_migration_in_another_directory_counts_without_configuration(): void
    {
        $this->write('modules/Billing/migrations/2024_02_01_add_total_to_payments.php', self::migration("Schema::table('payments', fn () => null);"));

        $this->assertAffected(['tests/ModuleTest.php'], 'modules/Billing/migrations/2024_02_01_add_total_to_payments.php');
    }

    #[Test]
    public function a_table_from_the_default_path_is_found_for_a_migration_elsewhere(): void
    {
        $this->write('modules/Billing/migrations/2024_02_01_add_user_to_payments.php', self::migration(
            "Schema::table('users', fn () => null);",
        ));

        $this->assertAffected(['tests/UserTest.php'], 'modules/Billing/migrations/2024_02_01_add_user_to_payments.php');
    }

    #[Test]
    public function a_php_file_that_is_not_a_migration_is_not_its_business(): void
    {
        $this->write('database/seeders/InvoiceSeeder.php', "<?php DB::table('invoices')->insert([]);\n");

        $this->assertAffected(self::EVERYTHING, 'database/seeders/InvoiceSeeder.php');
    }

    #[Test]
    public function a_migration_in_an_unknown_directory_is_resolved_through_the_configured_paths(): void
    {
        $this->write('modules/Shipping/migrations/2024_02_01_add_parcel_to_users.php', self::migration("Schema::table('users', fn () => null);"));
        $this->write('modules/Shipping/migrations/2024_02_02_alter_parcels.php', self::migration("Schema::table('parcels', fn () => null);"));

        $this->assertAffected(['tests/UserTest.php'], 'modules/Shipping/migrations/2024_02_01_add_parcel_to_users.php');
        // `parcels` was created somewhere none of the paths cover.
        $this->assertAffected(self::EVERYTHING, 'modules/Shipping/migrations/2024_02_02_alter_parcels.php');
    }

    #[Test]
    public function it_reads_the_migrations_once_for_every_changed_migration(): void
    {
        $this->write('database/migrations/2024_02_01_add_total_to_invoices.php', self::migration("Schema::table('invoices', fn () => null);"));
        $this->write('database/migrations/2024_02_02_add_name_to_users.php', self::migration("Schema::table('users', fn () => null);"));
        $graph = $this->graph($resolver = new MigrationResolver);

        $graph->affected(['database/migrations/2024_02_01_add_total_to_invoices.php']);
        $cached = (new \ReflectionProperty($resolver, 'migrations'))->getValue($resolver);
        $graph->affected(['database/migrations/2024_02_02_add_name_to_users.php']);

        $this->assertCount(1, $cached);
        $this->assertSame($cached, (new \ReflectionProperty($resolver, 'migrations'))->getValue($resolver));
    }

    #[Test]
    public function a_new_partial_runs_the_tests_that_rendered_a_view_including_it(): void
    {
        $this->write('resources/views/partials/discount.blade.php', "<p></p>\n");

        $this->assertAffected(['tests/InvoiceTest.php'], 'resources/views/partials/discount.blade.php');
    }

    #[Test]
    public function a_view_nothing_uses_yet_is_left_to_the_next_resolver(): void
    {
        $this->write('resources/views/partials/unused.blade.php', "<p></p>\n");

        $this->assertAffected(self::EVERYTHING, 'resources/views/partials/unused.blade.php');
    }

    #[Test]
    public function any_dynamic_include_leaves_views_to_the_next_resolver(): void
    {
        // users/index could render partials/discount too.
        $this->write('resources/views/users/index.blade.php', "@include('partials.'.\$type)\n");
        $this->write('resources/views/partials/discount.blade.php', "<p></p>\n");

        $this->assertAffected(self::EVERYTHING, 'resources/views/partials/discount.blade.php');
    }

    #[Test]
    public function a_new_livewire_component_runs_the_tests_that_rendered_a_view_using_it(): void
    {
        $this->write('resources/views/users/index.blade.php', "<ul></ul>\n<livewire:user-badge />\n");
        $this->write('resources/views/components/⚡user-badge.blade.php', "<?php new class extends Livewire\\Component {}; ?>\n<span></span>\n");

        $this->assertAffected(['tests/UserTest.php'], 'resources/views/components/⚡user-badge.blade.php');
    }

    #[Test]
    public function the_resolvers_work_on_their_own(): void
    {
        $this->write('database/migrations/2024_02_01_add_total_to_invoices.php', self::migration("Schema::table('invoices', fn () => null);"));
        $this->write('resources/views/partials/discount.blade.php', "<p></p>\n");

        // Each claims its own kind of file and leaves the other alone.
        $this->assertAffected(['tests/InvoiceTest.php'], 'database/migrations/2024_02_01_add_total_to_invoices.php', new MigrationResolver);
        $this->assertAffected(self::EVERYTHING, 'resources/views/partials/discount.blade.php', new MigrationResolver);
        $this->assertAffected(['tests/InvoiceTest.php'], 'resources/views/partials/discount.blade.php', new ViewResolver);
        $this->assertAffected(self::EVERYTHING, 'database/migrations/2024_02_01_add_total_to_invoices.php', new ViewResolver);
    }

    #[Test]
    public function a_deleted_file_is_left_to_the_next_resolver(): void
    {
        $this->assertAffected(self::EVERYTHING, 'database/migrations/2024_03_01_deleted.php');
        $this->assertAffected(self::EVERYTHING, 'resources/views/partials/deleted.blade.php');
    }

    /**
     * @param  list<string>  $expected
     */
    private function assertAffected(array $expected, string $changed, ?EdgeAwareResolver $resolver = null): void
    {
        $affected = $this->graph($resolver ?? new LaravelResolver)->affected([$changed]);
        sort($affected);

        $this->assertSame($expected, $affected);
    }

    /**
     * @param  array<string, list<string>>  $links  test file => more project-relative files
     */
    private function graph(EdgeAwareResolver $resolver, array $links = []): Graph
    {
        $graph = new Graph($this->root);
        $graph->setTestPaths(new TestPaths(directories: ['tests'], files: [], suffixes: ['Test.php']));
        $graph->link('tests/InvoiceTest.php', $this->root.'/database/migrations/2024_01_02_create_invoices_table.php');
        $graph->link('tests/InvoiceTest.php', $this->root.'/resources/views/invoices/show.blade.php');
        $graph->link('tests/InvoiceTest.php', $this->root.'/resources/views/partials/total.blade.php');
        $graph->link('tests/UserTest.php', $this->root.'/database/migrations/2024_01_01_create_users_table.php');
        $graph->link('tests/UserTest.php', $this->root.'/resources/views/users/index.blade.php');
        $graph->link('tests/ModuleTest.php', $this->root.'/modules/Billing/migrations/2024_01_03_create_payments_table.php');

        foreach ($links as $test => $files) {
            foreach ($files as $file) {
                $graph->link($test, $this->root.'/'.$file);
            }
        }

        $graph->setResolvers([$resolver, new FullSuite]);

        return $graph;
    }

    /**
     * @param  list<string>  $files
     * @return list<string>
     */
    private function sorted(array $files): array
    {
        sort($files);

        return $files;
    }

    private static function migration(string $up): string
    {
        return "<?php\n\nreturn new class extends Migration\n{\n    public function up(): void\n    {\n        {$up}\n    }\n};\n";
    }
}

/**
 * Stands in for a project's own fallback that runs the whole suite.
 */
final class FullSuite implements Resolver
{
    public function resolve(string $projectRoot, string $changedRelativePath): array
    {
        return ['tests/InvoiceTest.php', 'tests/ModuleTest.php', 'tests/UserTest.php'];
    }
}
