<?php

declare(strict_types=1);

namespace Fruitcake\PhpUnitTia\Laravel\Tests\Feature;

use Fruitcake\PhpUnitTia\Laravel\EdgeRecorder;
use Illuminate\Support\Facades\DB;
use JMac\Testing\PhpUnit\Tia\Tia;
use PHPUnit\Framework\Attributes\Test;

final class RecordsLaravelEdgesTest extends TestCase
{
    protected function beforeTestIsPrepared(): void
    {
        // A seeder, factory or request in setUp().
        DB::table('users')->insert(['name' => 'Seeded']);
        view('partials.total')->render();
    }

    #[Test]
    public function it_keeps_what_set_up_used(): void
    {
        $this->assertLinked([
            'database/migrations/2024_01_01_000000_create_users_table.php',
            'resources/views/partials/total.blade.php',
        ]);
    }

    #[Test]
    public function it_links_each_file_once(): void
    {
        view('partials.total')->render();
        view('partials.total')->render();

        $this->assertSame(array_values(array_unique($this->linked())), $this->linked());
        $this->assertCount(1, array_keys($this->linked(), self::app('resources/views/partials/total.blade.php'), true));
    }

    #[Test]
    public function it_hands_the_links_over_in_tear_down(): void
    {
        view('users.index')->render();

        $this->assertSame([], $this->results->links(), 'nothing is linked before tearDown()');

        $this->tearDownRecordsLaravelEdges();

        $this->assertContains(self::app('resources/views/users/index.blade.php'), array_map(realpath(...), $this->results->links()[self::TEST_FILE]));
    }

    #[Test]
    public function it_records_nothing_when_the_run_does_not_record(): void
    {
        Tia::recordInto(null);

        $this->assertNull(EdgeRecorder::record($this->app, EdgeRecorder::defaultCollectors()));
    }

    #[Test]
    public function the_default_collectors_are_created_once(): void
    {
        $this->assertSame(EdgeRecorder::defaultCollectors(), EdgeRecorder::defaultCollectors());
    }
}
