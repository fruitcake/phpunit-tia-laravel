<?php

namespace App\Providers;

use App\Admin\InvoiceResource;
use Illuminate\Support\ServiceProvider;

final class AdminServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        InvoiceResource::routes();
    }
}
