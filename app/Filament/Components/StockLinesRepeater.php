<?php

namespace App\Filament\Components;

use App\Domain\Catalog\Models\Unit;
use App\Domain\Catalog\Models\Variation;
use App\Domain\Catalog\Services\UnitConverter;
use App\Domain\Inventory\Services\StockLedger;
use App\Settings\InventorySettings;
use App\Support\Decimal;
use Closure;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;

/** Item lines for stock documents: product, unit (base + allowed sub-units), quantity, optional cost and lot. */
final class StockLinesRepeater
{
    /**
     * @param  bool|Closure(Get): bool  $withCost  show the cost column (incoming documents)
     * @param  string|null  $availableAt  form field holding the branch whose available quantity is shown per line (outgoing documents)
     */
    public static function make(string $name = 'items', bool|Closure $withCost = false, bool $withLots = true, ?string $availableAt = null): Repeater
    {
        $settings = app(InventorySettings::class);
        $showLots = $withLots && ($settings->enable_lots || $settings->enable_expiry);
        $costVisible = fn (Get $get) => $withCost instanceof Closure ? $withCost($get) : $withCost;

        $columns = [
            TableColumn::make(__('Product'))->markAsRequired()->width('40%'),
            TableColumn::make(__('Unit'))->markAsRequired(),
            TableColumn::make(__('Quantity'))->markAsRequired(),
            ...($availableAt ? [TableColumn::make(__('Available'))] : []),
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
            Select::make('unit_id')->required()->live()
                ->options(function (Get $get) {
                    $variation = $get('variation_id') ? Variation::query()->with('product')->find($get('variation_id')) : null;

                    return $variation ? app(UnitConverter::class)->options($variation->product) : [];
                }),
            TextInput::make('qty')->numeric()->minValue(0.0001)->required()->default(1)->live(onBlur: true),
            ...($availableAt ? [self::available($availableAt)] : []),
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

    /** What the branch has of the line's item, in the line's unit — red when the quantity asks for more. */
    private static function available(string $locationField): TextEntry
    {
        $quantity = function (Get $get) use ($locationField): ?float {
            $variation = $get('variation_id') ? Variation::query()->with('product')->find($get('variation_id')) : null;
            $location = $get('../../'.$locationField);
            if (! $variation || ! $location) {
                return null;
            }
            $unit = $get('unit_id') ? Unit::query()->find($get('unit_id')) : null;
            $multiplier = $unit ? app(UnitConverter::class)->multiplier($variation->product, $unit) : Decimal::of(1);
            $onHand = app(StockLedger::class)->onHand($variation->id, (int) $location);

            return (float) Decimal::toDb(Decimal::divide($onHand, $multiplier), 2);
        };

        return TextEntry::make('available')->hiddenLabel()
            ->state(fn (Get $get) => $quantity($get))
            ->numeric(2)->placeholder('—')->weight('bold')
            ->color(fn (Get $get) => ($available = $quantity($get)) !== null && (float) $get('qty') > $available ? 'danger' : 'success');
    }
}
