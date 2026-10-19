<?php

declare(strict_types=1);

namespace Fruitcake\PhpUnitTia\Laravel;

use JMac\Testing\PhpUnit\Tia\Traits\RunWithTia;

/**
 * Everything a Laravel base TestCase needs: phpunit-tia's RunWithTia, which
 * skips unaffected tests, and RecordsUsedFiles, which records the views,
 * migrations, Livewire components and Inertia pages each test uses.
 *
 * Use this trait rather than its parts. Once PHPUnit records test impact
 * data itself, RunWithTia goes and this trait stays.
 */
trait RunWithLaravelTia
{
    use RecordsUsedFiles;
    use RunWithTia;
}
