<?php

namespace App\Filament\Admin\Resources\StockTransfers\Pages;

use App\Filament\Admin\Resources\StockTransfers\StockTransferResource;
use App\Filament\Concerns\ScopedToFiscalYear;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListStockTransfers extends ListRecords
{
    use ScopedToFiscalYear;

    protected static string $resource = StockTransferResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
