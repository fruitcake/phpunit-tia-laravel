# phpunit-tia for Laravel

Laravel support for [phpunit-tia](https://github.com/jasonmccreary/phpunit-tia), the test impact analysis extension that skips the PHPUnit tests a change cannot affect.

Line coverage only sees PHP that runs inside `<source>`. It misses the views a Laravel test renders (Blade runs a compiled copy in `storage/`) and the migrations of the tables it queries. Without this package, phpunit-tia can skip a test that a view or migration change affects. Or it falls back to a directory guess that reruns far more than needed.

With this package, each test is linked to:

- every view it renders: pages, layouts, includes, Blade components, mail templates.
- the migrations of every table it queries.
- the Livewire 4 single-file and multi-file components it renders.
- the Inertia page component of every response it gets.

New files no test has used yet are resolved too. A new migration runs the tests that use the tables it changes. A new partial or component runs the tests of the views that use it.

## Requirements

- PHP 8.4 and Laravel 13
- PHPUnit 13.2.6 or newer
- [pcov](https://github.com/krakjoe/pcov) or [Xdebug](https://xdebug.org/) in `coverage` mode, for the runs that record. Replaying a recorded baseline works without one.

## Setup

Four steps cover a regular Laravel app, including Livewire and Inertia.

**1. Install the package** together with phpunit-tia:

```shell
composer require --dev fruitcake/phpunit-tia-laravel:dev-main jasonmccreary/phpunit-tia:dev-main
```

For now, both have to come from `dev-main`. This package relies on parts of phpunit-tia that are merged but not yet in a tagged release, and has no release of its own yet. Composer only installs a development version when your project asks for it directly, not when another package does, so phpunit-tia has to be in the command too. Once both are tagged, `composer require --dev fruitcake/phpunit-tia-laravel` will be enough.

**2. Register the extension** in `phpunit.xml`:

```xml
<extensions>
    <bootstrap class="JMac\Testing\PhpUnit\Tia\Extension"/>
</extensions>
```

**3. Add the trait** to `tests/TestCase.php`:

```php
namespace Tests;

use Fruitcake\PhpUnitTia\Laravel\RunWithLaravelTia;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use RunWithLaravelTia;
}
```

**4. Add a `phpunit-tia.php`** next to `phpunit.xml`, so new files are resolved:

```php
<?php

return [
    'resolvers' => [
        Fruitcake\PhpUnitTia\Laravel\LaravelResolver::class,
    ],
];
```

That's it. The first run records a baseline. After that, a run only executes the tests your changes can affect and marks the rest as skipped (`S`). Locally the baseline lives in `~/.phpunit-tia`; to use TIA in CI, persist it between runs as described in [phpunit-tia's CI docs](https://github.com/jasonmccreary/phpunit-tia#ci-workflows).

Useful switches, all from phpunit-tia:

```shell
PHPUNIT_TIA=0 php artisan test        # run everything, ignore TIA
PHPUNIT_TIA_FRESH=1 php artisan test  # rebuild the baseline
PHPUNIT_TIA_DEBUG=1 php artisan test  # explain why each test ran
```

## What runs after a change

A changed file that a test is already linked to runs that test. For new files, the resolver answers only when it is sure. Otherwise it leaves the file to phpunit-tia's fallback, which runs the tests linked to other files in the same directory.

| Change | Runs |
| --- | --- |
| A view, component or migration a test used | That test |
| New migration that alters `invoices` | The tests that use `invoices` |
| New migration with `->constrained('users')` or `->on('users')` | Also the tests that use `users` |
| New migration that only creates a new table | Nothing; no test can use that table yet |
| New migration that alters a table with no earlier migration (such as a package's table) | Fallback |
| New migration it cannot fully read: `Schema::connection()`, `Schema::table($this->table)`, concatenated SQL, a model, `app('db')` | Fallback |
| New partial, Blade component or Livewire component | The tests of the views, `app/` classes and route files that use it, directly or through other views |
| New view that nothing uses yet | Fallback |
| Any new view, while some view picks one at runtime (`@include($name)`, `<x-dynamic-component :component="$name">`, `@livewire($name)`) | Fallback |
| A class with no executable lines (an enum with only cases, an interface, a class with only constants) | The tests of the files that name it: through `use`, its full name (also as a string), or its short name in the same namespace |
| A new class that nothing names yet | Fallback |
| A config or route file, `bootstrap/app.php`, a factory, a seeder, a schema dump, a translation or `.env.testing` | Every test |
| Any other test code, such as the base `TestCase`, a trait or a fixture | Every test |

Everything from `setUp()` to `tearDown()` counts, so a request, factory or seeder in `setUp()` links its views and tables too.

## Customizing

None of this is needed for a regular setup.

### Your TestCase already has `setUp()`

The trait brings its own `setUp()`. Alias it and call it first:

```php
abstract class TestCase extends BaseTestCase
{
    use RunWithLaravelTia {
        RunWithLaravelTia::setUp as tiaSetUp;
    }

    protected function setUp(): void
    {
        $this->tiaSetUp();

        // ...
    }
}
```

A test that phpunit-tia skips never gets past that call, but `tearDown()` still runs. Guard any of your own teardown that needs the application with `if (! $this->skippedByTia())`.

### You already use `RunWithTia`

Swap it for `RunWithLaravelTia`, or keep it and add `Fruitcake\PhpUnitTia\Laravel\RecordsLaravelEdges` next to it. The combined trait is exactly those two.

### Migrations outside `database/migrations`

Recording follows every directory the migrator runs, `loadMigrationsFrom()` paths included. The resolver treats any file that extends `Migration` as a migration. It looks for the earlier migrations of its tables in `database/migrations` and in its own directory. When a module alters a table created elsewhere, pass those paths (globs work):

```php
return [
    'resolvers' => [
        new Fruitcake\PhpUnitTia\Laravel\LaravelResolver(
            migrationPaths: ['database/migrations', 'modules/*/database/migrations'],
        ),
    ],
];
```

### Choosing what is recorded

Recording is done by four collectors in `Fruitcake\PhpUnitTia\Laravel\Collectors`: `Views`, `Queries`, `Livewire` and `Inertia`. Livewire and Inertia do nothing when the package isn't installed. To leave one out or add your own, override `edgeCollectors()`:

```php
use Fruitcake\PhpUnitTia\Laravel\EdgeRecorder;

abstract class TestCase extends BaseTestCase
{
    use RunWithLaravelTia;

    private static ?array $collectors = null;

    protected function edgeCollectors(): array
    {
        return self::$collectors ??= [...EdgeRecorder::defaultCollectors(), new FixtureFiles];
    }
}
```

A collector implements `Collector`. `register()` runs once per test with that test's application; add each file the test uses to `$links`, as an absolute or project-relative path:

```php
use Fruitcake\PhpUnitTia\Laravel\Collectors\Collector;
use Fruitcake\PhpUnitTia\Laravel\Links;
use Illuminate\Contracts\Foundation\Application;

final class FixtureFiles implements Collector
{
    public function register(Application $app, Links $links): void
    {
        $app->make('events')->listen(FixtureLoaded::class, fn (FixtureLoaded $event) => $links->add($event->path));
    }
}
```

Return the same collector instances every time, as above, so a collector can keep what it reads between tests.

### Files of your own that every test depends on

Coverage doesn't see files outside `<source>`, so no test is linked to them. `ApplicationResolver` runs every test for the ones every Laravel app has (`ApplicationResolver::PATHS`), and for test code that is not a test itself: any other file in the test suite directories of your `phpunit.xml` or the `autoload-dev` paths of your `composer.json`, such as `tests/TestCase.php` beside `tests/Unit` and `tests/Feature`. Add paths of your own, such as a script that tests run:

```php
use Fruitcake\PhpUnitTia\Laravel\LaravelResolver;
use Fruitcake\PhpUnitTia\Laravel\Resolvers\ApplicationResolver;

return [
    'resolvers' => [
        new LaravelResolver(applicationPaths: [...ApplicationResolver::PATHS, 'scripts/*']),
    ],
];
```

### Choosing how new files are resolved

`LaravelResolver` combines `ApplicationResolver`, `MigrationResolver`, `ViewResolver` and `ClassResolver` from `Fruitcake\PhpUnitTia\Laravel\Resolvers`, in that order. Register them separately to leave one out or to put a resolver of your own between them. Each implements phpunit-tia's `EdgeAwareResolver`:

```php
use Fruitcake\PhpUnitTia\Laravel\Resolvers\ApplicationResolver;
use Fruitcake\PhpUnitTia\Laravel\Resolvers\ClassResolver;
use Fruitcake\PhpUnitTia\Laravel\Resolvers\MigrationResolver;
use Fruitcake\PhpUnitTia\Laravel\Resolvers\ViewResolver;

return [
    'resolvers' => [
        ApplicationResolver::class,
        new MigrationResolver(['database/migrations', 'modules/*/database/migrations']),
        App\Testing\TranslationResolver::class,
        ViewResolver::class,
        ClassResolver::class,
    ],
];
```

A resolver returns the test files a changed path affects, an empty list when it affects none, or `null` to leave the path to the next resolver.

## Caveats

- `RefreshDatabase` migrates and seeds once per process, in the first test. Only that test is linked to the tables the seeders touch. Use `DatabaseMigrations` or seed in `setUp()` to link every test.
- Inertia pages are linked, not the JavaScript components they import. A PHP test sees which page renders, not what it looks like.
- New views are found in `resources/views`, new Livewire components in Livewire's default locations, and their users in `resources/views`, `app/` and `routes/`. Files elsewhere, such as package views, are still linked once a test renders them; only new ones fall back.
- Table names are read with regular expressions, not a SQL parser. A missed table means fewer links, so keep a full run on your main branch as the baseline.

## Testing

```shell
composer test
```

The end-to-end tests run the fixture app in `tests/Fixtures/app` with the real extension, so they need pcov or Xdebug. Without a coverage driver they are skipped.

## License

MIT
