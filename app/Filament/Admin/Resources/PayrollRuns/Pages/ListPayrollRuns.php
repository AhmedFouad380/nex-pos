<?php

namespace App\Filament\Admin\Resources\PayrollRuns\Pages;

use App\Filament\Admin\Resources\PayrollRuns\PayrollRunResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPayrollRuns extends ListRecords
{
    protected static string $resource = PayrollRunResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label(__('New payroll'))];
    }
}
