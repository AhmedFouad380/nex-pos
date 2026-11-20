<?php

namespace App\Filament\Admin\Resources\Printing\Pages;

use App\Domain\Printing\Models\InvoiceLayout;
use App\Filament\Admin\Resources\Printing\InvoiceLayoutResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageInvoiceLayouts extends ManageRecords
{
    protected static string $resource = InvoiceLayoutResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->after(fn (InvoiceLayout $record) => InvoiceLayoutResource::keepSingleDefault($record))];
    }
}
