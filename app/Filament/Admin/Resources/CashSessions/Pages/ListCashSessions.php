<?php

namespace App\Filament\Admin\Resources\CashSessions\Pages;

use App\Filament\Admin\Resources\CashSessions\CashSessionResource;
use App\Filament\Concerns\ScopedToFiscalYear;
use Filament\Resources\Pages\ListRecords;

class ListCashSessions extends ListRecords
{
    use ScopedToFiscalYear;

    protected static string $resource = CashSessionResource::class;
}
