<?php

namespace App\Filament\Admin\Resources\Purchases\Pages;

use App\Filament\Admin\Resources\Purchases\PurchaseResource;
use App\Filament\Concerns\ScopedToFiscalYear;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPurchases extends ListRecords
{
    use ScopedToFiscalYear;

    protected static string $resource = PurchaseResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
