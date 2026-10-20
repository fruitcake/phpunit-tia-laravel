<?php

declare(strict_types=1);

namespace Fruitcake\PhpUnitTia\Laravel;

use Closure;
use Illuminate\Contracts\Foundation\Application;
use PHPUnit\Framework\TestCase;
use PHPUnit\Metadata\Api\CodeCoverage as CodeCoverageMetadata;
use PHPUnit\Runner\CodeCoverage;
use PHPUnit\TextUI\Configuration\Registry;
use ReflectionClass;

/**
 * Booting the application runs code that every test executes but few
 * depend on: registering routes calls into every Filament resource and
 * page. Line coverage links each of those files to every test, so a change
 * to one resource runs the whole suite. The boot is left out of the
 * coverage; the service providers it loaded are linked instead, because a
 * change to one can affect every test.
 *
 * A class that only runs while the application boots, other than a service
 * provider, is then linked to the tests that use it after booting.
 */
final class BootCoverage
{
    /**
     * Boot without collecting coverage for the test, when PHPUnit collects
     * it for that test.
     *
     * @param  Closure(): void  $boot
     */
    public static function leaveOut(TestCase $test, Closure $boot): void
    {
        if (! self::isCollecting($test)) {
            $boot();

            return;
        }

        $coverage = CodeCoverage::instance();
        $coverage->stop(false);

        try {
            $boot();
        } finally {
            $coverage->start($test);
        }
    }

    /**
     * The files of the loaded service providers that coverage would link.
     *
     * @return list<string>
     */
    public static function serviceProviders(Application $app): array
    {
        $coverage = CodeCoverage::instance();

        if (! $coverage->isActive()) {
            return [];
        }

        $filter = $coverage->codeCoverage()->filter();
        $files = [];

        foreach (array_keys($app->getLoadedProviders()) as $provider) {
            if (! class_exists($provider)) {
                continue;
            }

            $file = (new ReflectionClass($provider))->getFileName();

            if ($file !== false && ! $filter->isExcluded($file)) {
                $files[] = $file;
            }
        }

        return $files;
    }

    /**
     * The same check TestRunner::run() makes before it starts collecting:
     * starting coverage for a test PHPUnit leaves out would record it.
     */
    private static function isCollecting(TestCase $test): bool
    {
        if (! CodeCoverage::instance()->isActive()) {
            return false;
        }

        return Registry::get()->disableCoverageTargeting()
            || (new CodeCoverageMetadata)->shouldCodeCoverageBeCollectedFor($test);
    }
}
