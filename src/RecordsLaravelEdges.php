<?php

declare(strict_types=1);

namespace Fruitcake\PhpUnitTia\Laravel;

use Fruitcake\PhpUnitTia\Laravel\Collectors\Collector;

/**
 * Use next to RunWithTia in a Laravel base TestCase. Laravel calls
 * setUpRecordsLaravelEdges() once the application of each test is created,
 * and the hooks it installs link the test to the views it renders, the
 * migrations of the tables it queries, and the Livewire components and
 * Inertia pages it renders. Everything from setUp() to tearDown() counts.
 */
trait RecordsLaravelEdges
{
    private ?Links $laravelEdges = null;

    protected function setUpRecordsLaravelEdges(): void
    {
        $this->laravelEdges = EdgeRecorder::record($this->app, $this->edgeCollectors());
    }

    protected function tearDownRecordsLaravelEdges(): void
    {
        EdgeRecorder::link($this->laravelEdges);

        $this->laravelEdges = null;
    }

    /**
     * Override to add a collector of your own, or to leave one out. Return
     * the same instances every time to let them keep what they read.
     *
     * @return list<Collector>
     */
    protected function edgeCollectors(): array
    {
        return EdgeRecorder::defaultCollectors();
    }
}
