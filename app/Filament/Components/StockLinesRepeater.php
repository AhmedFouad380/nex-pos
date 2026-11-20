<?php

namespace App\Filament\Components;

use App\Domain\Catalog\Models\Variation;
use App\Domain\Catalog\Services\UnitConverter;
use App\Settings\InventorySettings;
use Closure;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;

/** Item lines for stock documents: product, unit (base + allowed sub-units), quantity, optional cost and lot. */
final class StockLinesRepeater
{
    /** @param bool|Closure(Get): bool $withCost show the cost column (incoming documents) */
    public static function make(string $name = 'items', bool|Closure $withCost = false, bool $withLots = true): Repeater
    {
        $settings = app(InventorySettings::class);
        $showLots = $withLots && ($settings->enable_lots || $settings->enable_expiry);
        $costVisible = fn (Get $get) => $withCost instanceof Closure ? $withCost($get) : $withCost;

        $columns = [
            TableColumn::make(__('Product'))->markAsRequired()->width('40%'),
            TableColumn::make(__('Unit'))->markAsRequired(),
            TableColumn::make(__('Quantity'))->markAsRequired(),
            TableColumn::make(__('Unit cost')),
        ];
        if ($showLots) {
            $columns[] = TableColumn::make(__('Lot'));
            $columns[] = TableColumn::make(__('Expiry date'));
        }

        $schema = [
            VariationSelect::make('variation_id', stockTrackedOnly: true)
                ->live()
                ->afterStateUpdated(function ($state, Set $set) {
                    $variation = $state ? Variation::query()->with('product')->find($state) : null;
                    $set('unit_id', $variation?->product->unit_id);
                    $set('unit_cost', $variation ? (float) $variation->purchase_price : null);
                })
                ->distinct(),
            Select::make('unit_id')->required()
                ->options(function (Get $get) {
                    $variation = $get('variation_id') ? Variation::query()->with('product')->find($get('variation_id')) : null;

                    return $variation ? app(UnitConverter::class)->options($variation->product) : [];
                }),
            TextInput::make('qty')->numeric()->minValue(0.0001)->required()->default(1),
            TextInput::make('unit_cost')->numeric()->minValue(0)
                ->visible($costVisible)
                ->placeholder(__('Purchase price')),
        ];
        if ($showLots) {
            $schema[] = TextInput::make('lot_number')->maxLength(60);
            $schema[] = DatePicker::make('exp_date');
        }

        return Repeater::make($name)
            ->label(__('Items'))
            ->table($columns)
            ->schema($schema)
            ->minItems(1)
            ->defaultItems(1)
            ->addActionLabel(__('Add item'))
            ->columnSpanFull();
    }
}
