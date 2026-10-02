<?php

declare(strict_types=1);

namespace Fruitcake\PhpUnitTia\Laravel\Tests\Unit;

use Fruitcake\PhpUnitTia\Laravel\BladeReferences;
use Fruitcake\PhpUnitTia\Laravel\Tests\Support\TemporaryProject;
use Fruitcake\PhpUnitTia\Laravel\ViewReferences;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ViewReferencesTest extends TestCase
{
    use TemporaryProject;

    protected function setUp(): void
    {
        $this->setUpTemporaryProject();
    }

    protected function tearDown(): void
    {
        $this->tearDownTemporaryProject();
    }

    #[Test]
    public function it_follows_includes_and_components_up_to_the_views_and_classes_that_use_them(): void
    {
        $this->write('resources/views/partials/total.blade.php', "<p>{{ \$total }}</p>\n");
        $this->write('resources/views/components/invoice-card.blade.php', "@include('partials.total')\n");
        $this->write('resources/views/invoices/show.blade.php', "@extends('layouts.app')\n<x-invoice-card />\n");
        $this->write('resources/views/emails/invoice.blade.php', "@include(\"partials.total\", ['total' => 1])\n");
        $this->write('resources/views/unrelated.blade.php', "<x-invoice-cards />\n@include('partials.totals')\n{{-- @include('partials.total') --}}\n");
        $this->write('app/Http/Controllers/InvoiceController.php', "<?php return view('invoices.show');\n");
        $this->write('app/Mail/InvoiceMail.php', "<?php new Content(markdown: 'emails.invoice');\n");
        $this->write('app/Mail/OtherMail.php', "<?php new Content(markdown: 'emails.invoice-reminder');\n");

        $views = new ViewReferences($this->root);
        $using = $views->using('resources/views/partials/total.blade.php');
        sort($using);

        $this->assertSame([
            'app/Http/Controllers/InvoiceController.php',
            'app/Mail/InvoiceMail.php',
            'resources/views/components/invoice-card.blade.php',
            'resources/views/emails/invoice.blade.php',
            'resources/views/invoices/show.blade.php',
        ], $using);
        $this->assertSame([], $views->dynamicReferences());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function componentConventions(): iterable
    {
        yield 'index file' => ['resources/views/components/card/index.blade.php', '<x-card>Hi</x-card>'];
        yield 'index file by full name' => ['resources/views/components/card/index.blade.php', '<x-card.index />'];
        yield 'file named after its directory' => ['resources/views/components/card/card.blade.php', '<x-card />'];
        yield 'nested file named after its directory' => ['resources/views/components/forms/input/input.blade.php', '<x-forms.input />'];
        yield 'nested' => ['resources/views/components/forms/text-input.blade.php', '<x-forms.text-input name="a" />'];
        yield 'underscore' => ['resources/views/components/user_avatar.blade.php', '<x-user-avatar/>'];
        yield 'attribute colon' => ['resources/views/components/alert.blade.php', '<x-alert:title>'];
        yield 'literal dynamic component' => ['resources/views/components/alert.blade.php', '<x-dynamic-component component="alert" />'];
    }

    #[Test]
    #[DataProvider('componentConventions')]
    public function it_matches_a_component_by_the_tags_it_is_used_with(string $component, string $usage): void
    {
        $this->write($component, "<div></div>\n");
        $this->write('resources/views/dashboard.blade.php', $usage."\n");

        $this->assertSame(['resources/views/dashboard.blade.php'], (new ViewReferences($this->root))->using($component));
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function literalReferences(): iterable
    {
        yield 'include' => ["@include('a.b')", ['a.b']];
        yield 'include with data' => ["@include('a.b', ['x' => fn () => foo(1, 2)])", ['a.b']];
        yield 'include if' => ["@includeIf('a.b')", ['a.b']];
        yield 'include when' => ["@includeWhen(\$user->can('edit', \$post), 'a.b', ['x' => 1])", ['a.b']];
        yield 'include unless' => ["@includeUnless(\$hidden, 'a.b')", ['a.b']];
        yield 'include first' => ["@includeFirst(['custom.admin', 'admin'], ['status' => 'x'])", ['admin', 'custom.admin']];
        yield 'extends' => ["@extends('layouts.app')", ['layouts.app']];
        yield 'component directive' => ["@component('mail::button', ['url' => \$url])", ['mail::button']];
        yield 'each' => ["@each('jobs.row', \$jobs, 'job', 'jobs.empty')", ['jobs.empty', 'jobs.row']];
        yield 'view helper' => ["{!! view('a.b')->render() !!}", ['a.b']];
        yield 'view factory' => ["@if(view()->exists('a.b')) @endif", []];
        yield 'blade comment' => ['{{-- @include($dynamic) --}}', []];
    }

    /**
     * @param  list<string>  $views
     */
    #[Test]
    #[DataProvider('literalReferences')]
    public function it_reads_literal_view_names(string $blade, array $views): void
    {
        $references = BladeReferences::parse($blade);
        $names = $references['views'];
        sort($names);

        $this->assertSame($views, $names);
        $this->assertFalse($references['dynamic']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function dynamicReferences(): iterable
    {
        yield 'include variable' => ['@include($partial)'];
        yield 'include concatenation' => ["@include('partials.'.\$type)"];
        yield 'include interpolation' => ['@include("partials.{$type}")'];
        yield 'include when variable' => ['@includeWhen($show, $partial)'];
        yield 'include first variable' => ['@includeFirst($candidates)'];
        yield 'include first with a variable entry' => ["@includeFirst(['custom.'.\$theme, 'admin'])"];
        yield 'extends variable' => ['@extends($layout)'];
        yield 'each variable' => ["@each(\$row, \$items, 'item')"];
        yield 'each variable empty view' => ["@each('row', \$items, 'item', \$empty)"];
        yield 'view helper variable' => ['{!! view($name) !!}'];
        yield 'dynamic component' => ['<x-dynamic-component :component="$name" />'];
        yield 'livewire variable' => ['@livewire($component)'];
        yield 'livewire dynamic component' => ['<livewire:dynamic-component :is="$name" />'];
        yield 'livewire is' => ['<livewire:is :component="$name" />'];
    }

    #[Test]
    #[DataProvider('dynamicReferences')]
    public function it_reports_a_view_named_at_runtime(string $blade): void
    {
        $this->assertTrue(BladeReferences::parse($blade)['dynamic']);
    }

    #[Test]
    public function a_file_is_not_matched_by_its_parent_directory_alone(): void
    {
        $this->write('resources/views/components/forms/card.blade.php', "<div></div>\n");
        $this->write('resources/views/dashboard.blade.php', "<x-forms />\n");

        $this->assertSame([], (new ViewReferences($this->root))->using('resources/views/components/forms/card.blade.php'));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function livewireComponents(): iterable
    {
        yield 'single file' => ['resources/views/components/⚡badge.blade.php', '<livewire:badge />'];
        yield 'single file without emoji' => ['resources/views/components/badge.blade.php', '<livewire:badge/>'];
        yield 'nested' => ['resources/views/components/⚡forms/⚡date-picker.blade.php', '<livewire:forms.date-picker wire:model="date" />'];
        yield 'livewire location' => ['resources/views/livewire/⚡badge.blade.php', '<livewire:badge />'];
        yield 'page' => ['resources/views/pages/⚡orders.blade.php', '<livewire:pages::orders />'];
        yield 'multi file' => ['resources/views/components/⚡stepper/stepper.blade.php', '<livewire:stepper />'];
        yield 'index' => ['resources/views/components/⚡stepper/index.blade.php', '<livewire:stepper />'];
        yield 'directive' => ['resources/views/components/⚡badge.blade.php', "@livewire('badge', ['user' => \$user])"];
        yield 'literal dynamic component' => ['resources/views/components/⚡badge.blade.php', '<livewire:dynamic-component is="badge" />'];
    }

    #[Test]
    #[DataProvider('livewireComponents')]
    public function it_matches_a_livewire_component_by_the_name_it_is_used_with(string $component, string $usage): void
    {
        $this->write($component, "<div></div>\n");
        $this->write('resources/views/dashboard.blade.php', $usage."\n");

        $this->assertSame(['resources/views/dashboard.blade.php'], (new ViewReferences($this->root))->using($component));
    }

    #[Test]
    public function it_finds_a_namespaced_livewire_component_in_a_route_file(): void
    {
        $this->write('resources/views/pages/⚡orders.blade.php', "<div></div>\n");
        $this->write('routes/web.php', "<?php\n\nRoute::livewire('/orders', 'pages::orders');\n");
        $this->write('app/Models/Order.php', "<?php\n\nprotected \$table = 'orders';\n");

        $this->assertSame(['routes/web.php'], (new ViewReferences($this->root))->using('resources/views/pages/⚡orders.blade.php'));
    }

    #[Test]
    public function it_skips_namespaced_components(): void
    {
        $this->assertSame([], BladeReferences::parse('<x-mail::button url="x">Go</x-mail::button>')['components']);
    }

    #[Test]
    public function it_walks_a_large_view_tree(): void
    {
        $this->write('resources/views/partials/nav.blade.php', "<nav></nav>\n");
        $this->write('resources/views/layout.blade.php', "@include('partials.nav')\n");

        for ($i = 0; $i < 1500; $i++) {
            $this->write("resources/views/pages/p{$i}.blade.php", "@extends('layout')\n<x-card />\n");
            $this->write("app/Http/Controllers/C{$i}.php", "<?php return view('pages.p{$i}');\n");
        }

        $using = (new ViewReferences($this->root))->using('resources/views/partials/nav.blade.php');

        // The layout, every page extending it, and every controller rendering a page.
        $this->assertCount(1 + 1500 + 1500, $using);
        $this->assertContains('resources/views/layout.blade.php', $using);
        $this->assertContains('resources/views/pages/p1499.blade.php', $using);
        $this->assertContains('app/Http/Controllers/C1499.php', $using);
    }

    #[Test]
    public function it_lists_the_views_with_a_dynamic_reference(): void
    {
        $this->write('resources/views/a.blade.php', "@include('b')\n");
        $this->write('resources/views/b.blade.php', "@include(\$partial)\n");

        $this->assertSame(['resources/views/b.blade.php'], (new ViewReferences($this->root))->dynamicReferences());
    }
}
