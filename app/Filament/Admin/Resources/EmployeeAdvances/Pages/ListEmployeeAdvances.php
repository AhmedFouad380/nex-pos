<?php

namespace App\Filament\Admin\Resources\EmployeeAdvances\Pages;

use App\Filament\Admin\Resources\EmployeeAdvances\AdvanceActions;
use App\Filament\Admin\Resources\EmployeeAdvances\EmployeeAdvanceResource;
use Filament\Resources\Pages\ListRecords;

class ListEmployeeAdvances extends ListRecords
{
    protected static string $resource = EmployeeAdvanceResource::class;

    protected function getHeaderActions(): array
    {
        return [AdvanceActions::give()];
    }
}
