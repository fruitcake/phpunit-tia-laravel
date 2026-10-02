<?php

declare(strict_types=1);

namespace Fruitcake\PhpUnitTia\Laravel\Tests\Unit;

use Fruitcake\PhpUnitTia\Laravel\LaravelResolver;
use Fruitcake\PhpUnitTia\Laravel\Resolvers\ApplicationResolver;
use Fruitcake\PhpUnitTia\Laravel\Tests\Support\TemporaryProject;
use JMac\Testing\PhpUnit\Tia\Graph;
use JMac\Testing\PhpUnit\Tia\TestPaths;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Runs the resolver through core's Graph::affected() without a fallback
 * after it, so a path it leaves alone runs nothing.
 */
final class ApplicationResolverTest extends TestCase
{
    use TemporaryProject;

    /** Every test the graph knows; ones it does not know run anyway. */
    private const array EVERYTHING = ['modules/Billing/tests/PaymentTest.php', 'tests/Feature/InvoiceTest.php', 'tests/Unit/UserTest.php'];

    protected function setUp(): void
    {
        $this->setUpTemporaryProject();

        $this->write('app/Models/Invoice.php', "<?php\n");
        $this->write('tests/Feature/InvoiceTest.php', "<?php\n");
        $this->write('tests/Unit/UserTest.php', "<?php\n");
        $this->write('tests/Unit/NewTest.php', "<?php\n");
        $this->write('modules/Billing/tests/PaymentTest.php', "<?php\n");
    }

    protected function tearDown(): void
    {
        $this->tearDownTemporaryProject();
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function filesEveryTestDependsOn(): array
    {
        return [
            'config' => ['config/services.php'],
            'routes' => ['routes/web.php'],
            'bootstrap' => ['bootstrap/app.php'],
            'factory' => ['database/factories/InvoiceFactory.php'],
            'seeder' => ['database/seeders/DatabaseSeeder.php'],
            'translation' => ['lang/nl/invoices.php'],
            'old translation location' => ['resources/lang/nl/invoices.php'],
            'schema dump' => ['database/schema/mysql-schema.sql'],
            'testing environment' => ['.env.testing'],
            'base test case' => ['tests/TestCase.php'],
            'test trait' => ['tests/Concerns/SignsTokens.php'],
            'test fixture' => ['tests/Fixtures/invoice.json'],
        ];
    }

    #[Test]
    #[DataProvider('filesEveryTestDependsOn')]
    public function a_file_every_test_depends_on_runs_every_test(string $path): void
    {
        $this->assertAffected(self::EVERYTHING, $path, new LaravelResolver);
    }

    #[Test]
    public function other_files_are_left_to_the_fallback(): void
    {
        $this->assertAffected([], 'app/Support/Money.php', new ApplicationResolver);
        $this->assertAffected([], 'bootstrap/cache/packages.php', new ApplicationResolver);
        $this->assertAffected([], 'README.md', new ApplicationResolver);
    }

    #[Test]
    public function a_changed_test_only_runs_itself(): void
    {
        $this->assertAffected(['tests/Unit/UserTest.php'], 'tests/Unit/UserTest.php', new ApplicationResolver);
    }

    #[Test]
    public function a_helper_next_to_tests_runs_every_test_even_if_it_looks_like_a_migration(): void
    {
        $this->write('tests/Fixtures/2024_01_01_create_widgets_table.php', "<?php\n\nreturn new class extends Migration { public function up(): void { Schema::create('widgets', fn () => null); } };\n");

        $this->assertAffected(self::EVERYTHING, 'tests/Fixtures/2024_01_01_create_widgets_table.php', new LaravelResolver);
    }

    #[Test]
    public function the_test_directories_can_be_set(): void
    {
        $resolver = new ApplicationResolver(testDirectories: ['tests', 'modules/Billing/tests']);

        $this->assertAffected(self::EVERYTHING, 'modules/Billing/tests/BillingTestCase.php', $resolver);
        $this->assertAffected([], 'modules/Billing/tests/BillingTestCase.php', new ApplicationResolver);
    }

    #[Test]
    public function it_reads_the_test_directories_from_phpunit_xml(): void
    {
        // This run's own phpunit.xml.dist lists tests/Unit, tests/Feature and
        // tests/EndToEnd, not tests/ as a whole.
        $root = dirname(__DIR__, 2);
        $edges = new Graph($root);
        $edges->markKnownTestFiles(['tests/Unit/TablesTest.php']);
        $resolver = new ApplicationResolver;

        $this->assertSame(['tests/Unit/TablesTest.php'], $resolver->resolve($edges, $root, 'tests/Feature/TestCase.php'));
        // Outside the suites, but in this package's autoload-dev.
        $this->assertSame(['tests/Unit/TablesTest.php'], $resolver->resolve($edges, $root, 'tests/Support/Project.php'));
        $this->assertNull($resolver->resolve($edges, $root, 'src/Links.php'));
    }

    #[Test]
    public function a_base_test_case_beside_the_suites_runs_every_test(): void
    {
        // Laravel's layout: suites in tests/Unit and tests/Feature, the base
        // TestCase and its helpers directly in tests/, autoloaded as Tests\.
        $this->write('composer.json', json_encode(['autoload-dev' => ['psr-4' => ['Tests\\' => 'tests/']]]));
        $resolver = new ApplicationResolver(testDirectories: ['tests/Unit', 'tests/Feature']);

        $this->assertAffected(self::EVERYTHING, 'tests/TestCase.php', $resolver);
        $this->assertAffected(self::EVERYTHING, 'tests/CreatesApplication.php', $resolver);
        $this->assertAffected(self::EVERYTHING, 'tests/Concerns/SignsTokens.php', $resolver);
    }

    #[Test]
    public function without_an_autoload_dev_mapping_only_the_suites_count(): void
    {
        $resolver = new ApplicationResolver(testDirectories: ['tests/Unit', 'tests/Feature']);

        $this->assertAffected([], 'tests/TestCase.php', $resolver);
        $this->assertAffected(self::EVERYTHING, 'tests/Feature/Concerns/SignsTokens.php', $resolver);
    }

    #[Test]
    public function every_autoload_dev_mapping_counts_but_not_what_it_sits_in(): void
    {
        $this->write('composer.json', json_encode(['autoload-dev' => [
            'psr-4' => ['Tests\\' => 'tests/', 'Modules\\Billing\\Tests\\' => ['./modules/Billing/tests/']],
            'files' => ['tests/helpers.php'],
        ]]));
        $resolver = new ApplicationResolver(testDirectories: ['tests/Unit', 'modules/Billing/tests/Feature']);

        $this->assertAffected(self::EVERYTHING, 'modules/Billing/tests/BillingTestCase.php', $resolver);
        $this->assertAffected(self::EVERYTHING, 'tests/helpers.php', $resolver);
        // The module itself is application code.
        $this->assertAffected([], 'modules/Billing/src/Payment.php', $resolver);
    }

    #[Test]
    public function paths_of_your_own_run_every_test(): void
    {
        $resolver = new LaravelResolver(applicationPaths: [...ApplicationResolver::PATHS, 'scripts/*']);

        $this->assertAffected(self::EVERYTHING, 'scripts/precheck.sh', $resolver);
        $this->assertAffected(self::EVERYTHING, 'config/app.php', $resolver);
    }

    /**
     * @param  list<string>  $expected
     */
    private function assertAffected(array $expected, string $changed, object $resolver): void
    {
        $graph = new Graph($this->root);
        $graph->setTestPaths(new TestPaths(directories: ['tests', 'modules/Billing/tests'], files: [], suffixes: ['Test.php']));
        $graph->link('tests/Feature/InvoiceTest.php', $this->root.'/app/Models/Invoice.php');
        // Recorded, but covering nothing in <source>; tests/Unit/NewTest.php was never recorded.
        $graph->markKnownTestFiles(['tests/Unit/UserTest.php', 'modules/Billing/tests/PaymentTest.php']);
        $graph->setResolvers([$resolver]);

        $affected = $graph->affected([$changed]);
        sort($affected);

        $this->assertSame($expected, $affected);
    }
}
