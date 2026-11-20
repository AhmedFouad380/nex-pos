<?php

namespace App\Filament\Admin\Resources\Sales\Pages;

use App\Filament\Admin\Resources\Sales\SaleResource;
use App\Filament\Concerns\ScopedToFiscalYear;
use App\Filament\Pos\Pages\PointOfSale;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;

class ListSales extends ListRecords
{
    use ScopedToFiscalYear;

    protected static string $resource = SaleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('pos')->label(__('Open POS'))->icon('heroicon-o-shopping-bag')
                ->url(fn () => PointOfSale::getUrl(panel: 'pos'))->visible(fn () => auth()->user()?->can('pos.sell')),
        ];
    }
}
