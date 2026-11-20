<?php

namespace App\Filament\Admin\Resources\StockAdjustments\Pages;

use App\Filament\Admin\Resources\StockAdjustments\StockAdjustmentResource;
use App\Filament\Concerns\ScopedToFiscalYear;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListStockAdjustments extends ListRecords
{
    use ScopedToFiscalYear;

    protected static string $resource = StockAdjustmentResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
