<?php

namespace App\Filament\Admin\Resources\EmployeeAdvances\Pages;

use App\Filament\Admin\Resources\EmployeeAdvances\AdvanceActions;
use App\Filament\Admin\Resources\EmployeeAdvances\EmployeeAdvanceResource;
use App\Filament\Concerns\ScopedToFiscalYear;
use Filament\Resources\Pages\ListRecords;

class ListEmployeeAdvances extends ListRecords
{
    use ScopedToFiscalYear;

    protected static string $resource = EmployeeAdvanceResource::class;

    protected function getHeaderActions(): array
    {
        return [AdvanceActions::give()];
    }
}
