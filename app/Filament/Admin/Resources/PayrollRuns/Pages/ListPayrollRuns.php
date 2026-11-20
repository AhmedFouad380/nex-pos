<?php

namespace App\Filament\Admin\Resources\PayrollRuns\Pages;

use App\Filament\Admin\Resources\PayrollRuns\PayrollRunResource;
use App\Filament\Concerns\ScopedToFiscalYear;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPayrollRuns extends ListRecords
{
    use ScopedToFiscalYear;

    protected static string $resource = PayrollRunResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label(__('New payroll'))];
    }
}
