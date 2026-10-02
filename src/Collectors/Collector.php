<?php

declare(strict_types=1);

namespace Fruitcake\PhpUnitTia\Laravel\Collectors;

use Fruitcake\PhpUnitTia\Laravel\Links;
use Illuminate\Contracts\Foundation\Application;

/**
 * Observes one kind of file a test uses that its line coverage cannot show,
 * such as a rendered template or the migrations of a queried table.
 *
 * Called once per test, with the application that test created, before
 * anything in the test runs. Install the hooks there and add each file to
 * $links: absolute or project-relative paths, any number of times.
 */
interface Collector
{
    public function register(Application $app, Links $links): void;
}
