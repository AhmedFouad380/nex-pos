<?php

namespace App\Filament\Admin\Resources\Products\Schemas;

use App\Domain\Catalog\Enums\BarcodeType;
use App\Domain\Catalog\Enums\ProductType;
use App\Domain\Catalog\Enums\TaxType;
use App\Domain\Catalog\Models\PriceGroup;
use App\Domain\Catalog\Models\Unit;
use App\Domain\Catalog\Models\VariationTemplate;
use App\Domain\Foundation\Models\Location;
use App\Domain\Shared\Enums\DurationUnit;
use App\Filament\Components\VariationSelect;
use App\Settings\InventorySettings;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

final class ProductForm
{
    public static function configure(Schema $schema): Schema
    {
        $inventory = app(InventorySettings::class);

        return $schema->columns(3)->components([
            Grid::make(1)->columnSpan(2)->schema([
                Section::make(__('Product details'))->columns(2)->schema([
                    TextInput::make('name')->label(__('Name'))->required()->maxLength(255)->columnSpanFull(),
                    Select::make('type')->label(__('Type'))->options(ProductType::class)->default(ProductType::Single)
                        ->required()->live()->disabledOn('edit')
                        ->helperText(fn (string $operation) => $operation === 'edit' ? __('The type cannot be changed after creation.') : null),
                    TextInput::make('sku')->label(__('SKU'))->maxLength(60)->unique('products', 'sku', ignoreRecord: true)
                        ->helperText(__('Leave empty to generate automatically.')),
                    Select::make('category_id')->label(__('Category'))->relationship('category', 'name')->searchable()->preload()
                        ->visible($inventory->enable_categories),
                    Select::make('brand_id')->label(__('Brand'))->relationship('brand', 'name')->searchable()->preload()
                        ->visible($inventory->enable_brands),
                    Select::make('unit_id')->label(__('Unit'))->required()->live()
                        ->options(fn () => Unit::query()->whereNull('base_unit_id')->pluck('name', 'id'))
                        ->default($inventory->default_unit_id),
                    Select::make('sub_unit_ids')->label(__('Also sold / bought in'))->multiple()
                        ->options(fn (Get $get) => Unit::query()->where('base_unit_id', $get('unit_id'))->pluck('name', 'id'))
                        ->visible(fn (Get $get) => $inventory->enable_sub_units && filled($get('unit_id')))
                        ->helperText(__('Example: carton, dozen.')),
                    Textarea::make('description')->label(__('Description'))->columnSpanFull()->rows(2),
                ]),

                Section::make(__('Pricing'))->columns(3)
                    ->visible(fn (Get $get) => self::type($get) !== ProductType::Variable)
                    ->schema([
                        TextInput::make('purchase_price')->label(__('Purchase price (excl. tax)'))->numeric()->minValue(0)->default(0)
                            ->live(onBlur: true)->afterStateUpdated(fn (Get $get, Set $set) => self::applyMargin($get, $set))
                            ->hidden(fn (Get $get) => self::type($get) === ProductType::Service),
                        TextInput::make('margin')->label(__('Profit margin'))->numeric()->suffix('%')->dehydrated(false)
                            ->live(onBlur: true)->afterStateUpdated(fn (Get $get, Set $set) => self::applyMargin($get, $set))
                            ->afterStateHydrated(fn (Get $get, Set $set) => self::fillMargin($get, $set))
                            ->hidden(fn (Get $get) => self::type($get) === ProductType::Service),
                        TextInput::make('sell_price')->label(__('Selling price'))->numeric()->minValue(0)->required()
                            ->live(onBlur: true)->afterStateUpdated(fn (Get $get, Set $set) => self::fillMargin($get, $set)),
                        TagsInput::make('barcodes')->label(__('Barcodes'))->placeholder(__('Scan or type, then Enter'))->columnSpanFull()
                            ->hidden(fn (Get $get) => self::type($get) === ProductType::Service),
                        Repeater::make('group_prices')->label(__('Price group prices'))->columnSpanFull()->defaultItems(0)
                            ->table([TableColumn::make(__('Price group')), TableColumn::make(__('Price'))])
                            ->schema([
                                Select::make('price_group_id')->options(fn () => PriceGroup::query()->pluck('name', 'id'))->required()->distinct(),
                                TextInput::make('value')->numeric()->minValue(0)->required(),
                            ])
                            ->addActionLabel(__('Add price group price')),
                    ]),

                Section::make(__('Variations'))
                    ->visible(fn (Get $get) => self::type($get) === ProductType::Variable)
                    ->headerActions([self::generateVariationsAction()])
                    ->schema([
                        Repeater::make('variations')->hiddenLabel()->defaultItems(0)->reorderable()
                            ->table([
                                TableColumn::make(__('Variation'))->markAsRequired(), TableColumn::make(__('SKU')),
                                TableColumn::make(__('Purchase price')), TableColumn::make(__('Selling price'))->markAsRequired(),
                                TableColumn::make(__('Barcodes')),
                            ])
                            ->schema([
                                Hidden::make('id'),
                                Hidden::make('attribute_values'),
                                TextInput::make('name')->required()->maxLength(255),
                                TextInput::make('sku')->maxLength(60),
                                TextInput::make('purchase_price')->numeric()->minValue(0)->default(0),
                                TextInput::make('sell_price')->numeric()->minValue(0)->required(),
                                TagsInput::make('barcodes'),
                            ])
                            ->addActionLabel(__('Add variation')),
                    ]),

                Section::make(__('Combo components'))
                    ->description(__('Selling one combo takes these quantities out of stock.'))
                    ->visible(fn (Get $get) => self::type($get) === ProductType::Combo)
                    ->schema([
                        Repeater::make('combo_items')->hiddenLabel()->defaultItems(1)
                            ->table([TableColumn::make(__('Product'))->markAsRequired(), TableColumn::make(__('Quantity'))->markAsRequired()])
                            ->schema([
                                VariationSelect::make('component_variation_id', stockTrackedOnly: true),
                                TextInput::make('qty')->numeric()->minValue(0.0001)->default(1)->required(),
                            ])
                            ->addActionLabel(__('Add component')),
                    ]),
            ]),

            Grid::make(1)->columnSpan(1)->schema([
                Section::make(__('Tax'))->schema([
                    Select::make('tax_rate_id')->label(__('Tax rate'))->relationship('taxRate', 'name', fn ($query) => $query->where('is_active', true)->where('for_group_only', false)),
                    Select::make('tax_type')->label(__('Selling price is'))->options(TaxType::class)->default(TaxType::Inclusive)->required(),
                ]),
                Section::make(__('Stock'))
                    ->hidden(fn (Get $get) => in_array(self::type($get), [ProductType::Service, ProductType::Combo], true))
                    ->schema([
                        Toggle::make('track_stock')->label(__('Track stock'))->default(true)->live(),
                        TextInput::make('alert_qty')->label(__('Alert when stock falls below'))->numeric()->minValue(0)
                            ->visible(fn (Get $get) => $get('track_stock')),
                        TextInput::make('reorder_qty')->label(__('Reorder quantity'))->numeric()->minValue(0)
                            ->visible(fn (Get $get) => $get('track_stock')),
                        Toggle::make('track_lots')->label(__('Track lot numbers'))->visible(fn (Get $get) => $inventory->enable_lots && $get('track_stock')),
                        Toggle::make('track_expiry')->label(__('Track expiry dates'))->live()->visible(fn (Get $get) => $inventory->enable_expiry && $get('track_stock')),
                        Grid::make(2)->visible(fn (Get $get) => $get('track_expiry'))->schema([
                            TextInput::make('expiry_period')->label(__('Shelf life'))->numeric()->integer()->minValue(1),
                            Select::make('expiry_unit')->label(__('Unit'))->options(DurationUnit::class),
                        ]),
                        Toggle::make('track_serials')->label(__('Track serial / IMEI numbers'))->visible(fn (Get $get) => $inventory->enable_serials && $get('track_stock')),
                    ]),
                Section::make(__('Availability'))->schema([
                    Toggle::make('is_active')->label(__('Active'))->default(true),
                    Toggle::make('is_sellable')->label(__('Available for sale'))->default(true),
                    CheckboxList::make('location_ids')->label(__('Locations'))
                        ->options(fn () => Location::query()->where('is_active', true)->pluck('name', 'id'))
                        ->default(fn () => Location::query()->where('is_active', true)->pluck('id')->all())
                        ->bulkToggleable(),
                ]),
                Section::make(__('More'))->collapsed()->schema([
                    Select::make('barcode_type')->label(__('Barcode type'))->options(BarcodeType::class)->default(BarcodeType::C128),
                    Select::make('warranty_id')->label(__('Warranty'))->relationship('warranty', 'name'),
                    TextInput::make('weight')->label(__('Weight'))->numeric()->minValue(0),
                    TextInput::make('preparation_minutes')->label(__('Preparation time (minutes)'))->numeric()->integer()->minValue(0),
                ]),
            ]),
        ]);
    }

    public static function type(Get $get): ?ProductType
    {
        $type = $get('type');

        return $type instanceof ProductType ? $type : ProductType::tryFrom((string) $type);
    }

    /** sell = purchase × (1 + margin%), ignoring tax (margins are always on tax-exclusive prices). */
    private static function applyMargin(Get $get, Set $set): void
    {
        if (filled($get('margin')) && (float) $get('purchase_price') > 0) {
            $set('sell_price', round((float) $get('purchase_price') * (1 + (float) $get('margin') / 100), 2));
        }
    }

    private static function fillMargin(Get $get, Set $set): void
    {
        $purchase = (float) $get('purchase_price');
        $set('margin', $purchase > 0 ? round(((float) $get('sell_price') - $purchase) / $purchase * 100, 2) : null);
    }

    /** Builds every combination of the chosen template values (e.g. 4 sizes × 2 colors = 8 variations). */
    private static function generateVariationsAction(): Action
    {
        return Action::make('generateVariations')
            ->label(__('Generate from templates'))
            ->icon('heroicon-o-sparkles')
            ->schema([
                Repeater::make('attributes')->label(__('Attributes'))->minItems(1)->defaultItems(1)
                    ->schema([
                        Select::make('template_id')->label(__('Template'))->options(fn () => VariationTemplate::query()->pluck('name', 'id'))->required()->live(),
                        Select::make('values')->label(__('Values'))->multiple()->required()
                            ->options(fn (Get $get) => VariationTemplate::query()->find($get('template_id'))?->values()->orderBy('sort')->pluck('value', 'value') ?? []),
                    ])->columns(2),
                TextInput::make('purchase_price')->label(__('Purchase price (excl. tax)'))->numeric()->default(0),
                TextInput::make('sell_price')->label(__('Selling price'))->numeric()->required(),
            ])
            ->action(function (array $data, Get $get, Set $set): void {
                $templates = VariationTemplate::query()->pluck('name', 'id');
                $combinations = [[]];
                foreach ($data['attributes'] as $attribute) {
                    $name = $templates[$attribute['template_id']];
                    $next = [];
                    foreach ($combinations as $combo) {
                        foreach ($attribute['values'] as $value) {
                            $next[] = [...$combo, $name => $value];
                        }
                    }
                    $combinations = $next;
                }

                $existing = collect($get('variations') ?? [])->keyBy('name');
                $rows = collect($combinations)->map(function (array $values) use ($data, $existing) {
                    $name = implode(' / ', $values);

                    return $existing[$name] ?? [
                        'id' => null, 'name' => $name, 'attribute_values' => $values, 'sku' => null,
                        'purchase_price' => $data['purchase_price'], 'sell_price' => $data['sell_price'], 'barcodes' => [],
                    ];
                });

                $set('variations', $rows->values()->all());
            });
    }
}
