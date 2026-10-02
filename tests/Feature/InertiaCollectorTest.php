<?php

declare(strict_types=1);

namespace Fruitcake\PhpUnitTia\Laravel\Tests\Feature;

use Fruitcake\PhpUnitTia\Laravel\Collectors\Inertia as InertiaCollector;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\ResponseFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresMethod;
use PHPUnit\Framework\Attributes\Test;

#[RequiresMethod(ResponseFactory::class, 'render')]
final class InertiaCollectorTest extends TestCase
{
    protected function defineRoutes($router): void
    {
        Route::get('/invoices/{id}', fn () => Inertia::render('Invoices/Show'));
        Route::get('/missing', fn () => Inertia::render('Missing/Page'));
        Route::get('/plain', fn () => 'Invoices/Show');
    }

    #[Test]
    public function it_links_the_page_of_a_first_visit(): void
    {
        $this->get('/invoices/1')->assertOk();

        $this->assertLinked(['resources/js/pages/Invoices/Show.vue']);
    }

    #[Test]
    public function it_links_the_page_of_an_inertia_visit(): void
    {
        $this->get('/invoices/1', ['X-Inertia' => 'true'])->assertOk()->assertHeader('X-Inertia');

        $this->assertLinked(['resources/js/pages/Invoices/Show.vue']);
    }

    #[Test]
    public function it_links_nothing_for_a_page_that_does_not_exist_or_a_plain_response(): void
    {
        $this->get('/missing');
        $this->get('/plain');

        foreach ($this->linked() as $file) {
            $this->assertStringNotContainsString('resources/js', $file);
        }
    }

    /**
     * @return iterable<string, array{Response, ?string}>
     */
    public static function responses(): iterable
    {
        yield 'json' => [new Response('{"component":"Users/Index","props":{}}', 200, ['X-Inertia' => 'true']), 'Users/Index'];
        yield 'script tag' => [new Response('<script data-page="app" type="application/json">{"component":"Users/Index"}</script>'), 'Users/Index'];
        yield 'data-page attribute' => [new Response('<div id="app" data-page="{&quot;component&quot;:&quot;Users/Index&quot;}"></div>'), 'Users/Index'];
        yield 'plain html' => [new Response('<p>Users/Index</p>'), null];
        yield 'empty' => [new Response(''), null];
    }

    #[Test]
    #[DataProvider('responses')]
    public function it_reads_the_component_from_every_response_shape(Response $response, ?string $component): void
    {
        $this->assertSame($component, InertiaCollector::component($response));
    }
}
