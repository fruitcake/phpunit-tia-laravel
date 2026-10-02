<?php

declare(strict_types=1);

namespace Fruitcake\PhpUnitTia\Laravel\Tests\EndToEnd;

use Fruitcake\PhpUnitTia\Laravel\Tests\Support\Project;
use Fruitcake\PhpUnitTia\Laravel\Tests\Support\Result;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Runs the fixture app's suite with the real extension and a coverage
 * driver: one full run records the graph, then each test changes the
 * project and checks which of the fixture's tests run again.
 *
 * Fixture tests: InvoiceTest renders invoices/show (which includes
 * partials/total) and queries `invoices`; UserTest renders users/index
 * (which uses <x-avatar>) and queries `users`; SeededTest touches `users`
 * only in setUp(); LivewireTest renders a single-file and a multi-file
 * Livewire component; InertiaTest renders the Invoices/Show page;
 * CalculatorTest covers app/Calculator.php only.
 */
#[Group('end-to-end')]
final class ImpactTest extends TestCase
{
    private const int TOTAL = 8;

    private const array INVOICE_TESTS = ['InvoiceTest::it_shows_an_invoice', 'InvoiceTest::it_stores_an_invoice'];

    private const array USER_TESTS = ['SeededTest::it_has_a_seeded_user', 'UserTest::it_lists_users'];

    private const array LIVEWIRE_TESTS = ['LivewireTest::it_counts', 'LivewireTest::it_steps'];

    private const array ALL = [
        'CalculatorTest::it_adds', 'InertiaTest::it_renders_the_invoice_page', ...self::INVOICE_TESTS, ...self::LIVEWIRE_TESTS, ...self::USER_TESTS,
    ];

    private static ?Project $recorded = null;

    private Project $project;

    public static function setUpBeforeClass(): void
    {
        if (! Project::coverageDriverAvailable()) {
            return;
        }

        self::$recorded = Project::make();
        $result = self::$recorded->phpunit();

        self::assertSame(0, $result->exitCode, $result->output);
        self::assertCount(self::TOTAL, $result->ran, $result->output);
    }

    public static function tearDownAfterClass(): void
    {
        self::$recorded?->destroy();
        self::$recorded = null;
    }

    protected function setUp(): void
    {
        if (self::$recorded === null) {
            $this->markTestSkipped('Needs pcov or Xdebug to record coverage.');
        }

        $this->project = self::$recorded->clone();
    }

    protected function tearDown(): void
    {
        if (isset($this->project)) {
            $this->project->destroy();
        }
    }

    #[Test]
    public function nothing_runs_without_changes(): void
    {
        $result = $this->project->phpunit();

        $this->assertSame([], $result->ran, $result->output);
        $this->assertCount(self::TOTAL, $result->skipped);
    }

    #[Test]
    public function a_changed_partial_runs_the_tests_that_rendered_it(): void
    {
        $this->project->write('resources/views/partials/total.blade.php', "<p>Total due</p>\n");

        $this->assertRan(self::INVOICE_TESTS);
    }

    #[Test]
    public function a_changed_component_runs_the_tests_that_rendered_it(): void
    {
        $this->project->write('resources/views/components/avatar.blade.php', "<img alt=\"avatar\" class=\"round\">\n");

        $this->assertRan(['UserTest::it_lists_users']);
    }

    #[Test]
    public function a_changed_migration_runs_the_tests_that_query_its_table(): void
    {
        $this->project->write('database/migrations/2024_01_02_000000_create_invoices_table.php', str_replace(
            "\$table->integer('total');",
            "\$table->integer('total')->default(0);",
            (string) file_get_contents($this->project->path.'/database/migrations/2024_01_02_000000_create_invoices_table.php'),
        ));

        $this->assertRan(self::INVOICE_TESTS);
    }

    #[Test]
    public function a_new_partial_runs_the_tests_of_the_views_that_include_it(): void
    {
        // invoices/show already has @includeIf('partials.discount').
        $this->project->write('resources/views/partials/discount.blade.php', "<p>Discount</p>\n");

        $this->assertStringContainsString('LaravelResolver', $this->assertRan(self::INVOICE_TESTS)->output);
    }

    #[Test]
    public function a_new_partial_falls_back_while_a_view_includes_one_by_a_runtime_name(): void
    {
        $this->project->write('resources/views/users/show.blade.php', "@include(\$profile)\n");
        $this->project->commit('Add a view with a dynamic include');
        $this->recordAgain();

        $this->project->write('resources/views/partials/discount.blade.php', "<p>Discount</p>\n");

        // The sibling guess: every test linked to a file in partials/.
        $this->assertStringContainsString('shares a directory', $this->assertRan(self::INVOICE_TESTS)->output);
    }

    #[Test]
    public function a_new_migration_runs_the_tests_that_query_its_table(): void
    {
        $this->project->write('database/migrations/2024_02_01_000000_add_paid_to_invoices.php', self::migration(
            "Schema::table('invoices', function (Blueprint \$table) { \$table->boolean('paid')->default(false); });",
        ));

        $this->assertRan(self::INVOICE_TESTS);
    }

    #[Test]
    public function a_new_migration_with_a_foreign_key_runs_the_tests_of_both_tables(): void
    {
        $this->project->write('database/migrations/2024_02_01_000000_add_user_to_invoices.php', self::migration(
            "Schema::table('invoices', function (Blueprint \$table) { \$table->foreignId('user_id')->nullable()->constrained(); });",
        ));

        $this->assertRan([...self::INVOICE_TESTS, ...self::USER_TESTS]);
    }

    #[Test]
    public function a_migration_of_a_new_table_runs_nothing(): void
    {
        $this->project->write('database/migrations/2024_02_01_000000_create_payments_table.php', self::migration(
            "Schema::create('payments', function (Blueprint \$table) { \$table->id(); });",
        ));

        $this->assertRan([]);
    }

    #[Test]
    public function a_migration_it_cannot_read_completely_falls_back(): void
    {
        $this->project->write('database/migrations/2024_02_01_000000_create_audits_table.php', self::migration(
            "Schema::create('audits', function (Blueprint \$table) { \$table->id(); });\n"
            ."        Schema::table(\$this->table, function (Blueprint \$table) { \$table->string('note')->nullable(); });",
            'protected string $table = \'users\';',
        ));

        // The sibling guess: every test linked to a migration.
        $this->assertRan([...self::INVOICE_TESTS, ...self::USER_TESTS]);
    }

    #[Test]
    public function a_table_used_only_in_set_up_is_linked_too(): void
    {
        $this->project->write('database/migrations/2024_02_01_000000_add_email_to_users.php', self::migration(
            "Schema::table('users', function (Blueprint \$table) { \$table->string('email')->nullable(); });",
        ));

        $this->assertRan(self::USER_TESTS);
    }

    #[Test]
    public function a_changed_livewire_single_file_component_runs_the_tests_that_rendered_it(): void
    {
        $this->project->write('resources/views/components/⚡counter.blade.php', str_replace(
            '<div>',
            '<div class="counter">',
            (string) file_get_contents($this->project->path.'/resources/views/components/⚡counter.blade.php'),
        ));

        $this->assertRan(self::LIVEWIRE_TESTS);
    }

    #[Test]
    public function a_changed_livewire_multi_file_component_script_runs_the_tests_that_rendered_it(): void
    {
        $this->project->write('resources/views/components/⚡stepper/stepper.js', "export default { step: 2 }\n");

        $this->assertRan(self::LIVEWIRE_TESTS);
    }

    #[Test]
    public function a_changed_inertia_page_runs_the_tests_that_rendered_it(): void
    {
        $this->project->write('resources/js/pages/Invoices/Show.vue', "<template><h1>Invoice #1</h1></template>\n");

        $this->assertRan(['InertiaTest::it_renders_the_invoice_page']);
    }

    #[Test]
    public function a_new_config_file_runs_every_test(): void
    {
        $this->project->write('config/billing.php', "<?php\n\nreturn ['currency' => 'EUR'];\n");

        $this->assertCount(self::TOTAL, $this->assertRan(self::ALL)->ran);
    }

    #[Test]
    public function a_changed_base_test_case_runs_every_test(): void
    {
        // A code change: phpunit-tia ignores changes to comments only.
        $this->project->write('tests/TestCase.php', str_replace(
            'use RunWithLaravelTia;',
            "use RunWithLaravelTia;\n\n    protected bool \$seed = false;",
            (string) file_get_contents($this->project->path.'/tests/TestCase.php'),
        ));

        $this->assertRan(self::ALL);
    }

    #[Test]
    public function a_changed_class_runs_the_tests_that_cover_it(): void
    {
        $this->project->write('app/Calculator.php', str_replace('$a + $b', '$b + $a', (string) file_get_contents($this->project->path.'/app/Calculator.php')));

        $this->assertRan(['CalculatorTest::it_adds']);
    }

    /**
     * @param  list<string>  $expected
     */
    private function assertRan(array $expected): Result
    {
        sort($expected);
        $result = $this->project->phpunit(['PHPUNIT_TIA_DEBUG' => '1']);

        $this->assertSame(0, $result->exitCode, $result->output);
        $this->assertSame($expected, $result->ran, $result->output);

        return $result;
    }

    private function recordAgain(): void
    {
        $result = $this->project->phpunit(['PHPUNIT_TIA_FRESH' => '1']);

        $this->assertSame(0, $result->exitCode, $result->output);
    }

    private static function migration(string $up, string $properties = ''): string
    {
        return <<<PHP
        <?php

        use Illuminate\Database\Migrations\Migration;
        use Illuminate\Database\Schema\Blueprint;
        use Illuminate\Support\Facades\Schema;

        return new class extends Migration
        {
            {$properties}

            public function up(): void
            {
                {$up}
            }
        };
        PHP;
    }
}
