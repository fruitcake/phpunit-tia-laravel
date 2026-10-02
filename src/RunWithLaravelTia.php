<?php

declare(strict_types=1);

namespace Fruitcake\PhpUnitTia\Laravel;

use JMac\Testing\PhpUnit\Tia\Traits\RunWithTia;

/**
 * Everything a Laravel base TestCase needs: phpunit-tia's RunWithTia, which
 * skips unaffected tests, and RecordsLaravelEdges, which links each test to
 * the views, migrations, Livewire components and Inertia pages it uses.
 */
trait RunWithLaravelTia
{
    use RecordsLaravelEdges;
    use RunWithTia;
}
