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
 * Links a test to the Laravel files its coverage cannot see, through a set
 * of collectors. Ported from Pest's BladeEdges, TableTracker and
 * InertiaEdges, which record the same things for Pest's own TIA.
 */
final class EdgeRecorder
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
     * @return Links|null where the test's files collect, or null when this
     *                    run records nothing
     */
    public static function record(Application $app, array $collectors): ?Links
    {
        if (! Tia::isRecording()) {
            return null;
        }

        $links = new Links;

        foreach ($collectors as $collector) {
            $collector->register($app, $links);
        }

        return $links;
    }

    /**
     * Hand a test's files to phpunit-tia. Call it from tearDown(): it only
     * takes links once PHPUnit reports the test as prepared, which happens
     * after setUp().
     */
    public static function link(?Links $links): void
    {
        if ($links !== null) {
            Tia::link(...$links->all());
        }
    }
}
