<?php

declare(strict_types=1);

namespace Fruitcake\PhpUnitTia\Laravel;

use Closure;
use Fruitcake\PhpUnitTia\Laravel\Collectors\Collector;
use JMac\Testing\PhpUnit\Tia\Tia;

/**
 * Laravel calls setUpRecordsUsedFiles() once the application of each test is
 * created, and the hooks it installs collect the views the test renders, the
 * migrations of the tables it queries, and the Livewire components and
 * Inertia pages it renders. Everything from setUp() to tearDown() counts.
 * tearDown() hands them over to be recorded with what the test executed.
 *
 * Booting the application is left out of the coverage, and the service
 * providers it loaded are linked instead; see BootCoverage.
 */
trait RecordsUsedFiles
{
    private ?UsedFiles $usedFiles = null;

    protected function setUpRecordsUsedFiles(): void
    {
        $this->usedFiles = $this->shouldRecordUsedFiles()
            ? Recorder::record($this->app, $this->usedFileCollectors())
            : null;

        $this->usedFiles?->add(...BootCoverage::serviceProviders($this->app));
    }

    protected function refreshApplication(): void
    {
        $this->withoutBootCoverage(parent::refreshApplication(...));
    }

    /**
     * Boots the application without its coverage; see BootCoverage. A
     * TestCase with a refreshApplication() of its own wraps its boot in this.
     *
     * @param  Closure(): void  $boot
     */
    protected function withoutBootCoverage(Closure $boot): void
    {
        if (! $this->shouldRecordUsedFiles()) {
            $boot();

            return;
        }

        BootCoverage::leaveOut($this, $boot);
    }

    protected function tearDownRecordsUsedFiles(): void
    {
        if ($this->usedFiles !== null) {
            $this->registerUsedFiles(...$this->usedFiles->all());
        }

        $this->usedFiles = null;
    }

    /**
     * Only a run that records needs the files.
     */
    protected function shouldRecordUsedFiles(): bool
    {
        return Recorder::isRecording();
    }

    /**
     * Override to add a collector of your own, or to leave one out. Return
     * the same instances every time to let them keep what they read.
     *
     * @return list<Collector>
     */
    protected function usedFileCollectors(): array
    {
        return Recorder::defaultCollectors();
    }

    /**
     * Hands the files to phpunit-tia. This is an in-between step: once
     * PHPUnit records test impact data itself (sebastianbergmann/phpunit#6919),
     * this method calls $this->registerFixture() instead, and the collectors
     * and everything that overrides them stay as they are.
     */
    protected function registerUsedFiles(string ...$files): void
    {
        Tia::link(...$files);
    }
}
