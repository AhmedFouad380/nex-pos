<?php

namespace App\Filament\Admin\Resources\Suppliers\Pages;

use App\Filament\Admin\Resources\Suppliers\SupplierResource;
use App\Filament\Admin\Support\ContactStatementPage;

class SupplierStatement extends ContactStatementPage
{
    protected static string $resource = SupplierResource::class;
}
