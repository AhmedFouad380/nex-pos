<?php

namespace App\Filament\Admin\Resources\Catalog\Pages;

use App\Filament\Admin\Resources\Catalog\VariationTemplateResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageVariationTemplates extends ManageRecords
{
    protected static string $resource = VariationTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
