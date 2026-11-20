<?php

namespace App\Filament\Admin\Resources\Customers\Pages;

use App\Filament\Admin\Resources\Customers\CustomerResource;
use App\Filament\Admin\Support\ContactStatementPage;

class CustomerStatement extends ContactStatementPage
{
    protected static string $resource = CustomerResource::class;
}
