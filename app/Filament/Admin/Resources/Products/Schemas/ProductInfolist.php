<?php

namespace App\Filament\Admin\Resources\Products\Schemas;

use App\Domain\Catalog\Enums\ProductType;
use App\Domain\Catalog\Models\Product;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class ProductInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(3)->components([
            Section::make(__('Product details'))->columnSpan(2)->columns(3)->schema([
                TextEntry::make('name')->label(__('Name'))->columnSpanFull()->weight('bold'),
                TextEntry::make('sku')->label(__('SKU'))->copyable(),
                TextEntry::make('type')->label(__('Type'))->badge(),
                TextEntry::make('unit.name')->label(__('Unit')),
                TextEntry::make('category.name')->label(__('Category'))->placeholder('—'),
                TextEntry::make('brand.name')->label(__('Brand'))->placeholder('—'),
                TextEntry::make('taxRate.name')->label(__('Tax rate'))->placeholder('—'),
                TextEntry::make('description')->label(__('Description'))->columnSpanFull()->placeholder('—'),
            ]),
            Section::make(__('Status'))->columnSpan(1)->schema([
                IconEntry::make('is_active')->label(__('Active'))->boolean(),
                IconEntry::make('is_sellable')->label(__('Available for sale'))->boolean(),
                TextEntry::make('alert_qty')->label(__('Alert when stock falls below'))->numeric(2)->placeholder('—'),
                TextEntry::make('locations.name')->label(__('Locations'))->badge(),
            ]),
            Section::make(__('Variations & prices'))->columnSpanFull()->schema([
                RepeatableEntry::make('variations')->hiddenLabel()
                    ->table([
                        TableColumn::make(__('Variation')), TableColumn::make(__('SKU')), TableColumn::make(__('Purchase price')),
                        TableColumn::make(__('Selling price')), TableColumn::make(__('Barcodes')),
                    ])
                    ->schema([
                        TextEntry::make('name')->formatStateUsing(fn ($state) => $state === 'DEFAULT' ? '—' : $state),
                        TextEntry::make('sku'),
                        TextEntry::make('purchase_price')->numeric(2)->visible(fn () => auth()->user()?->can('products.view_purchase_price')),
                        TextEntry::make('sell_price_inc_tax')->numeric(2),
                        TextEntry::make('barcodes.barcode')->badge()->placeholder('—'),
                    ]),
            ]),
            Section::make(__('Stock by location'))->columnSpanFull()
                ->visible(fn (Product $record) => $record->tracksStock())
                ->schema([
                    RepeatableEntry::make('stockLevels')->hiddenLabel()
                        ->table([
                            TableColumn::make(__('Location')), TableColumn::make(__('Variation')), TableColumn::make(__('On hand')),
                            TableColumn::make(__('Average cost')), TableColumn::make(__('Stock value')),
                        ])
                        ->schema([
                            TextEntry::make('location.name'),
                            TextEntry::make('variation.name')->formatStateUsing(fn ($state) => $state === 'DEFAULT' ? '—' : $state),
                            TextEntry::make('qty_on_hand')->numeric(2),
                            TextEntry::make('avg_cost')->numeric(2),
                            TextEntry::make('value')->state(fn ($record) => (float) $record->qty_on_hand * (float) $record->avg_cost)->numeric(2),
                        ])
                        ->placeholder(__('No stock yet.')),
                ]),
            Section::make(__('Combo components'))->columnSpanFull()
                ->visible(fn (Product $record) => $record->type === ProductType::Combo)
                ->schema([
                    RepeatableEntry::make('defaultVariation.comboItems')->hiddenLabel()
                        ->table([TableColumn::make(__('Product')), TableColumn::make(__('Quantity'))])
                        ->schema([
                            TextEntry::make('componentVariation.sku')->formatStateUsing(fn ($state, $record) => $record->componentVariation->fullName()),
                            TextEntry::make('qty')->numeric(2),
                        ]),
                ]),
        ]);
    }
}
