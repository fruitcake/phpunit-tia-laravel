<?php

declare(strict_types=1);

namespace Fruitcake\PhpUnitTia\Laravel\Tests\Feature;

use Fruitcake\PhpUnitTia\Laravel\EdgeRecorder;
use Fruitcake\PhpUnitTia\Laravel\RecordsLaravelEdges;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\ServiceProvider as InertiaServiceProvider;
use JMac\Testing\PhpUnit\Tia\ResultCollector;
use JMac\Testing\PhpUnit\Tia\Tia;
use Livewire\LivewireServiceProvider;
use Orchestra\Testbench\TestCase as Testbench;

/**
 * A Laravel application on the views, migrations, Livewire components and
 * Inertia pages of tests/Fixtures/app, with the extension's ResultCollector
 * standing in for a recording run.
 */
abstract class TestCase extends Testbench
{
    use RecordsLaravelEdges;
    use RefreshDatabase;

    protected const string APP = __DIR__.'/../Fixtures/app';

    protected const string MODULE_MIGRATIONS = __DIR__.'/../Fixtures/module-migrations';

    protected const string TEST_FILE = 'tests/Feature/SomeTest.php';

    protected ResultCollector $results;

    protected function setUp(): void
    {
        $this->results = new ResultCollector;
        Tia::recordInto($this->results);

        parent::setUp();

        $this->beforeTestIsPrepared();

        // PHPUnit reports the test as prepared only after setUp().
        $this->results->testPrepared(static::class.'::'.$this->name(), self::TEST_FILE);
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        Tia::reset();
    }

    /** What a test's own setUp() would do, before PHPUnit sees it as running. */
    protected function beforeTestIsPrepared(): void {}

    protected function getPackageProviders($app): array
    {
        // Both are optional; CI also runs the suite without them.
        return array_values(array_filter(
            [LivewireServiceProvider::class, InertiaServiceProvider::class],
            class_exists(...),
        ));
    }

    protected function defineEnvironment($app): void
    {
        $root = (string) realpath(self::APP);

        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('view.paths', [$root.'/resources/views']);
        $app['config']->set('livewire.component_locations', [$root.'/resources/views/components']);
        $app['config']->set('livewire.component_namespaces', ['pages' => $root.'/resources/views/pages']);
        $app['config']->set('inertia.pages.paths', [$root.'/resources/js/pages']);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom((string) realpath(self::APP.'/database/migrations'));
        $this->loadMigrationsFrom((string) realpath(self::MODULE_MIGRATIONS));
    }

    /**
     * What the test linked so far, as tearDown() would hand it over.
     *
     * @return list<string> real paths
     */
    protected function linked(): array
    {
        EdgeRecorder::link($this->laravelEdges);

        return array_map(
            static fn (string $file): string => realpath($file) ?: $file,
            $this->results->links()[self::TEST_FILE] ?? [],
        );
    }

    /**
     * @param  list<string>  $files  paths in the fixture app
     */
    protected function assertLinked(array $files): void
    {
        $linked = $this->linked();

        foreach ($files as $file) {
            $this->assertContains(self::app($file), $linked);
        }
    }

    protected static function app(string $relative): string
    {
        return (string) realpath(self::APP.'/'.$relative);
    }
}
