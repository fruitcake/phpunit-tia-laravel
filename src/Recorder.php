<?php

declare(strict_types=1);

namespace Fruitcake\PhpUnitTia\Laravel;

use Fruitcake\PhpUnitTia\Laravel\Collectors\Collector;
use Fruitcake\PhpUnitTia\Laravel\Collectors\Inertia;
use Fruitcake\PhpUnitTia\Laravel\Collectors\Livewire;
use Fruitcake\PhpUnitTia\Laravel\Collectors\Queries;
use Fruitcake\PhpUnitTia\Laravel\Collectors\Views;
use Illuminate\Contracts\Foundation\Application;
use JMac\Testing\PhpUnit\Tia\Tia;

/**
 * Collects the Laravel files a test uses that its coverage cannot see,
 * through a set of collectors. Ported from Pest's BladeEdges, TableTracker
 * and InertiaEdges, which record the same things for Pest's own TIA.
 */
final class Recorder
{
    /** @var list<Collector>|null */
    private static ?array $defaults = null;

    /**
     * The built-in collectors, created once per run so what they read, such
     * as the migrations, is kept between tests.
     *
     * @return list<Collector>
     */
    public static function defaultCollectors(): array
    {
        return self::$defaults ??= [new Views, new Queries, new Livewire, new Inertia];
    }

    /**
     * Install the collectors on the application of the test about to run.
     *
     * @param  list<Collector>  $collectors
     * @return UsedFiles where the test's files collect
     */
    public static function record(Application $app, array $collectors): UsedFiles
    {
        $files = new UsedFiles;

        foreach ($collectors as $collector) {
            $collector->register($app, $files);
        }

        return $files;
    }

    /**
     * Whether this run records which files each test uses.
     */
    public static function isRecording(): bool
    {
        return Tia::isRecording();
    }
}
