<?php

namespace App\Filament\Admin\Resources\SaleReturns\Pages;

use App\Filament\Admin\Resources\SaleReturns\SaleReturnResource;
use App\Filament\Concerns\ScopedToFiscalYear;
use Filament\Resources\Pages\ListRecords;

class ListSaleReturns extends ListRecords
{
    use ScopedToFiscalYear;

    protected static string $resource = SaleReturnResource::class;
}
