<?php

namespace App\Filament\Admin\Resources\StockCounts\Pages;

use App\Filament\Admin\Resources\StockCounts\StockCountResource;
use App\Filament\Concerns\ScopedToFiscalYear;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListStockCounts extends ListRecords
{
    use ScopedToFiscalYear;

    protected static string $resource = StockCountResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label(__('New stock count'))];
    }
}
