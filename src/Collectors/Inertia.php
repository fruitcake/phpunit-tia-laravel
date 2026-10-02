<?php

declare(strict_types=1);

namespace Fruitcake\PhpUnitTia\Laravel\Collectors;

use Fruitcake\PhpUnitTia\Laravel\Links;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * The page component of every Inertia response a test receives, such as
 * resources/js/pages/Invoices/Show.vue for `Inertia::render('Invoices/Show')`.
 * Read from the response, as Pest's InertiaEdges does, and found on disk
 * through Inertia's own page finder.
 *
 * Only the page itself is linked, not the components it imports: a PHP test
 * observes which page renders, not what it looks like.
 */
final class Inertia implements Collector
{
    public function register(Application $app, Links $links): void
    {
        if (! $app->bound('inertia.view-finder')) {
            return;
        }

        // Per test, as the page paths are configuration.
        $pages = [];

        $app->make('events')->listen(RequestHandled::class, static function (RequestHandled $event) use ($app, $links, &$pages): void {
            $component = self::component($event->response);

            if ($component === null) {
                return;
            }

            $page = $pages[$component] ??= self::page($app, $component);

            if ($page !== null) {
                $links->add($page);
            }
        });
    }

    public static function component(Response $response): ?string
    {
        $content = $response->getContent();

        if (! is_string($content) || $content === '') {
            return null;
        }

        if ($response->headers->has('X-Inertia')) {
            return self::componentFromJson($content);
        }

        if (! str_contains($content, 'data-page')) {
            return null;
        }

        if (preg_match('#<script\b(?=[^>]*\bdata-page="app")(?=[^>]*\btype="application/json")[^>]*>(.+?)</script>#s', $content, $match) === 1) {
            return self::componentFromJson(html_entity_decode($match[1]));
        }

        if (preg_match('/\sdata-page="(\{[^"]+\})"/', $content, $match) === 1) {
            return self::componentFromJson(html_entity_decode($match[1]));
        }

        return null;
    }

    private static function componentFromJson(string $json): ?string
    {
        $page = json_decode($json, true);

        return is_array($page) && is_string($page['component'] ?? null) && $page['component'] !== ''
            ? $page['component']
            : null;
    }

    private static function page(Application $app, string $component): ?string
    {
        try {
            return $app->make('inertia.view-finder')->find($component);
        } catch (Throwable) {
            return null;
        }
    }
}
