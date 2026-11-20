<?php

namespace App\Filament\Admin\Resources\Catalog\Pages;

use App\Filament\Admin\Resources\Catalog\WarrantyResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageWarranties extends ManageRecords
{
    protected static string $resource = WarrantyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
