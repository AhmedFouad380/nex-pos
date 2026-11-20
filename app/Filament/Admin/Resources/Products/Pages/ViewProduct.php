<?php

namespace App\Filament\Admin\Resources\Products\Pages;

use App\Filament\Actions\ActivityHistoryAction;
use App\Filament\Admin\Resources\Products\ProductResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Database\Eloquent\Model;

class ViewProduct extends ViewRecord
{
    protected static string $resource = ProductResource::class;

    protected function getHeaderActions(): array
    {
        return [EditAction::make(), ActivityHistoryAction::make()];
    }

    protected function resolveRecord(int|string $key): Model
    {
        return parent::resolveRecord($key)->load([
            'unit', 'category', 'brand', 'taxRate', 'locations',
            'variations.barcodes', 'stockLevels.location', 'stockLevels.variation',
            'defaultVariation.comboItems.componentVariation.product',
        ]);
    }
}
