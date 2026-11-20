<?php

namespace App\Filament\Admin\Resources\Catalog\Pages;

use App\Filament\Admin\Resources\Catalog\PriceGroupResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManagePriceGroups extends ManageRecords
{
    protected static string $resource = PriceGroupResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
