<?php

namespace App\Filament\Admin\Resources\Purchases\Schemas;

use App\Domain\Catalog\Models\TaxRate;
use App\Domain\Catalog\Models\Variation;
use App\Domain\Catalog\Services\UnitConverter;
use App\Domain\CRM\Enums\ContactType;
use App\Domain\CRM\Models\Contact;
use App\Domain\Finance\Enums\PaymentMethodType;
use App\Domain\Finance\Models\PaymentMethod;
use App\Domain\Foundation\Models\Currency;
use App\Domain\Purchasing\Enums\CostDistribution;
use App\Domain\Purchasing\Enums\PurchaseStatus;
use App\Domain\Shared\Data\LineInput;
use App\Domain\Shared\Enums\DiscountType;
use App\Domain\Shared\Services\DocumentCalculator;
use App\Filament\Components\VariationSelect;
use App\Filament\Support\UserLocations;
use App\Settings\InventorySettings;
use App\Settings\PurchaseSettings;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

/** Purchase invoice form (also reused, without stock/payment parts, by purchase orders). */
final class PurchaseForm
{
    public static function configure(Schema $schema, bool $isOrder = false): Schema
    {
        $purchaseSettings = app(PurchaseSettings::class);
        $inventory = app(InventorySettings::class);

        return $schema->columns(4)->components([
            Select::make('supplier_id')->label(__('Supplier'))->required()->searchable()->columnSpan(2)
                ->options(fn () => Contact::query()->whereIn('type', [ContactType::Supplier, ContactType::Both])->where('is_active', true)
                    ->orderBy('name')->limit(500)->pluck('name', 'id'))
                ->getSearchResultsUsing(fn (string $search) => Contact::query()->whereIn('type', [ContactType::Supplier, ContactType::Both])
                    ->where(fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('code', 'like', "%{$search}%")->orWhere('mobile', 'like', "%{$search}%"))
                    ->limit(50)->pluck('name', 'id')),
            Select::make('location_id')->label(__('Location'))->required()
                ->options(fn () => UserLocations::options())->default(fn () => UserLocations::first()),
            DateTimePicker::make($isOrder ? 'order_date' : 'purchase_date')->label(__('Date'))->default(now())->required(),
            TextInput::make('supplier_ref')->label(__('Supplier invoice no.'))->maxLength(60)->visible(! $isOrder),
            Select::make('status')->label(__('Status'))->options(PurchaseStatus::class)->default(PurchaseStatus::Received)->required()
                ->visible(! $isOrder && $purchaseSettings->enable_purchase_status),
            DatePicker::make($isOrder ? 'expected_at' : 'due_date')->label($isOrder ? __('Expected delivery') : __('Due date'))
                ->helperText($isOrder ? null : __('Leave empty to use the supplier payment term.')),
            Select::make('currency_id')->label(__('Currency'))->visible(! $isOrder && $purchaseSettings->enable_foreign_currency)
                ->options(fn () => Currency::query()->where('is_active', true)->pluck('name', 'id'))->live(),
            TextInput::make('exchange_rate')->label(__('Exchange rate'))->numeric()->minValue(0.000001)->default($purchaseSettings->default_exchange_rate)
                ->visible(fn (Get $get) => ! $isOrder && $purchaseSettings->enable_foreign_currency && filled($get('currency_id'))),

            Repeater::make('items')->label(__('Items'))->columnSpanFull()->minItems(1)->defaultItems(1)->live(onBlur: true)
                ->addActionLabel(__('Add item'))
                ->table(array_values(array_filter([
                    TableColumn::make(__('Product'))->markAsRequired()->width('30%'),
                    TableColumn::make(__('Unit'))->markAsRequired(),
                    TableColumn::make(__('Quantity'))->markAsRequired(),
                    TableColumn::make(__('Unit cost'))->markAsRequired(),
                    TableColumn::make(__('Discount %')),
                    TableColumn::make(__('Tax')),
                    ! $isOrder && ($inventory->enable_lots || $inventory->enable_expiry) ? TableColumn::make(__('Lot')) : null,
                    ! $isOrder && $inventory->enable_expiry ? TableColumn::make(__('Expiry date')) : null,
                    ! $isOrder && $purchaseSettings->enable_editing_product_from_purchase ? TableColumn::make(__('New selling price')) : null,
                ])))
                ->schema(array_values(array_filter([
                    VariationSelect::make('variation_id')->live()
                        ->afterStateUpdated(function ($state, Set $set) {
                            $variation = $state ? Variation::query()->with('product')->find($state) : null;
                            $set('unit_id', $variation?->product->unit_id);
                            $set('unit_cost', $variation ? (float) $variation->purchase_price : null);
                            $set('tax_rate_id', $variation?->product->tax_rate_id);
                        }),
                    Select::make('unit_id')->required()->options(function (Get $get) {
                        $variation = $get('variation_id') ? Variation::query()->with('product')->find($get('variation_id')) : null;

                        return $variation ? app(UnitConverter::class)->options($variation->product) : [];
                    }),
                    TextInput::make('qty')->numeric()->minValue(0.0001)->default(1)->required(),
                    TextInput::make('unit_cost')->numeric()->minValue(0)->required(),
                    TextInput::make('discount_value')->numeric()->minValue(0)->maxValue(100)->default(0),
                    Select::make('tax_rate_id')->options(fn () => TaxRate::query()->where('is_active', true)->where('for_group_only', false)->pluck('name', 'id')),
                    ! $isOrder && ($inventory->enable_lots || $inventory->enable_expiry) ? TextInput::make('lot_number')->maxLength(60) : null,
                    ! $isOrder && $inventory->enable_expiry ? DatePicker::make('exp_date') : null,
                    ! $isOrder && $purchaseSettings->enable_editing_product_from_purchase ? TextInput::make('new_sell_price')->numeric()->minValue(0) : null,
                    Hidden::make('purchase_order_item_id'),
                ]))),

            Section::make(__('Totals'))->columnSpanFull()->columns(4)->schema([
                Select::make('discount_type')->label(__('Invoice discount'))->options(DiscountType::class)->live(),
                TextInput::make('discount_value')->label(__('Discount value'))->numeric()->minValue(0)->default(0)->live(onBlur: true)
                    ->visible(fn (Get $get) => filled($get('discount_type'))),
                Select::make('tax_rate_id')->label(__('Invoice tax'))->live()
                    ->options(fn () => TaxRate::query()->where('is_active', true)->where('for_group_only', false)->pluck('name', 'id')),
                TextInput::make('shipping_charges')->label(__('Shipping'))->numeric()->minValue(0)->default(0)->live(onBlur: true),
                Repeater::make('costs')->label(__('Additional costs (customs, transport...)'))->visible(! $isOrder)->defaultItems(0)
                    ->columnSpanFull()->live(onBlur: true)->addActionLabel(__('Add cost'))
                    ->table([TableColumn::make(__('Name'))->markAsRequired(), TableColumn::make(__('Amount'))->markAsRequired(), TableColumn::make(__('Distribute by'))])
                    ->schema([
                        TextInput::make('name')->required()->maxLength(255),
                        TextInput::make('amount')->numeric()->minValue(0)->required(),
                        Select::make('distribute_by')->options(CostDistribution::class)->default(CostDistribution::Value)->required(),
                    ]),
                TextEntry::make('totals_preview')->label(__('Total'))->columnSpanFull()->size('lg')->weight('bold')
                    ->state(fn (Get $get) => self::preview($get)),
            ]),

            Section::make(__('Payment'))->columnSpanFull()->columns(3)->visible(! $isOrder)->schema([
                TextInput::make('paid_now')->label(__('Paid now'))->numeric()->minValue(0)->default(0),
                Select::make('payment_method_id')->label(__('Payment method'))
                    ->options(fn () => PaymentMethod::query()->where('is_active', true)->where('type', '!=', PaymentMethodType::Advance)->pluck('name', 'id'))
                    ->default(fn () => PaymentMethod::query()->where('type', PaymentMethodType::Cash)->value('id'))
                    ->required(fn (Get $get) => (float) $get('paid_now') > 0),
                TextInput::make('payment_reference')->label(__('Reference'))->maxLength(100),
            ]),

            Textarea::make('note')->label(__('Note'))->rows(2)->columnSpanFull(),
        ]);
    }

    /** Live "subtotal − discount + tax + shipping + costs = total" line under the items. */
    public static function preview(Get $get): string
    {
        $rates = TaxRate::query()->pluck('rate', 'id');
        $lines = collect($get('items') ?? [])
            ->filter(fn ($l) => filled($l['qty'] ?? null) && filled($l['unit_cost'] ?? null))
            ->map(fn ($l) => new LineInput((string) $l['qty'], (string) $l['unit_cost'],
                (float) ($l['discount_value'] ?? 0) > 0 ? DiscountType::Percent : null, (string) ($l['discount_value'] ?? 0),
                (string) ($rates[$l['tax_rate_id'] ?? null] ?? 0)))
            ->values()->all();
        $costs = collect($get('costs') ?? [])->sum(fn ($c) => (float) ($c['amount'] ?? 0));
        $type = $get('discount_type');

        $totals = app(DocumentCalculator::class)->calculate($lines,
            $type instanceof DiscountType ? $type : DiscountType::tryFrom((string) $type), (string) ($get('discount_value') ?? 0),
            $rates[$get('tax_rate_id')] ?? 0, (float) ($get('shipping_charges') ?? 0) + $costs);

        $f = fn ($v) => number_format($v->toFloat(), 2);

        return __('Subtotal').' '.$f($totals->subtotal).'  −  '.__('Discount').' '.$f($totals->discountAmount)
            .'  +  '.__('Tax').' '.$f($totals->invoiceTax).'  +  '.__('Charges').' '.$f($totals->charges)
            .'  =  '.$f($totals->total);
    }
}
