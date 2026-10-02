<?php

declare(strict_types=1);

namespace Fruitcake\PhpUnitTia\Laravel\Tests\Feature;

use Fruitcake\PhpUnitTia\Laravel\Collectors\Collector;
use Fruitcake\PhpUnitTia\Laravel\Collectors\Queries;
use Fruitcake\PhpUnitTia\Laravel\Links;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;

/**
 * A TestCase that adds a collector of its own and leaves the Views
 * collector out, through edgeCollectors().
 */
final class CustomCollectorTest extends TestCase
{
    protected function edgeCollectors(): array
    {
        return [new Queries, new TranslationFiles];
    }

    #[Test]
    public function it_runs_the_collectors_the_test_case_chose(): void
    {
        __('invoices.title');
        view('partials.total')->render();
        DB::table('invoices')->count();

        $linked = $this->linked();

        $this->assertContains(self::app('database/migrations/2024_01_02_000000_create_invoices_table.php'), $linked);
        $this->assertContains($this->app->langPath('en/invoices.php'), $linked);
        $this->assertNotContains(self::app('resources/views/partials/total.blade.php'), $linked);
    }
}

/**
 * Example collector: the translation file of every group a test looks up.
 */
final class TranslationFiles implements Collector
{
    public function register(Application $app, Links $links): void
    {
        $app->afterResolving('translator', static function ($translator) use ($app, $links): void {
            $translator->handleMissingKeysUsing(static function (string $key) use ($app, $links): string {
                $links->add($app->langPath('en/'.explode('.', $key)[0].'.php'));

                return $key;
            });
        });
    }
}
