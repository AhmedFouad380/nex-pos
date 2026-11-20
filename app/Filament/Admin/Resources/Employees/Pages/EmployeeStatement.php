<?php

namespace App\Filament\Admin\Resources\Employees\Pages;

use App\Filament\Admin\Resources\Employees\EmployeeResource;
use App\Filament\Admin\Support\ContactStatementPage;

class EmployeeStatement extends ContactStatementPage
{
    protected static string $resource = EmployeeResource::class;
}
