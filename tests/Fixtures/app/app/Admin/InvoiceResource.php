<?php

namespace App\Admin;

use Illuminate\Support\Facades\Route;

/**
 * Like a Filament resource: booting the application registers its routes,
 * and only a test that opens one renders it.
 */
final class InvoiceResource
{
    public static function routes(): void
    {
        Route::get('/admin/invoices', fn () => self::index());
    }

    public static function index(): string
    {
        return 'Invoices';
    }
}
