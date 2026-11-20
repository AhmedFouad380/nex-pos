<?php

namespace App\Filament\Admin\Resources\SaleReturns;

use App\Domain\Finance\Enums\PaymentMethodType;
use App\Domain\Finance\Models\PaymentMethod;
use App\Domain\Sales\Models\Sale;
use App\Domain\Sales\Models\SaleReturn;
use App\Filament\Admin\Resources\SaleReturns\Pages\CreateSaleReturn;
use App\Filament\Admin\Resources\SaleReturns\Pages\ListSaleReturns;
use App\Filament\Admin\Resources\SaleReturns\Pages\ViewSaleReturn;
use App\Filament\Concerns\AuthorizesWithPermissions;
use App\Filament\Concerns\ScopesToUserLocations;
use App\Filament\NavigationGroup;
use App\Filament\Support\DocumentInfolist;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn as EntryColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class SaleReturnResource extends Resource
{
    use AuthorizesWithPermissions, ScopesToUserLocations;

    protected static ?string $model = SaleReturn::class;

    protected static string|\UnitEnum|null $navigationGroup = NavigationGroup::Sales;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUturnRight;

    protected static ?int $navigationSort = 20;

    protected static ?string $recordTitleAttribute = 'number';

    public static function getModelLabel(): string
    {
        return __('Sale return');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Sale returns');
    }

    protected static function permissionFor(string $action): string|false
    {
        return match ($action) {
            'viewAny', 'view' => 'sale_returns.view',
            'create' => 'sale_returns.create',
            default => false,
        };
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(3)->components([
            Hidden::make('sale_id')->required(),
            TextEntry::make('sale_info')->label(__('Invoice'))->state(function ($get) {
                $sale = Sale::query()->with('customer')->find($get('sale_id'));

                return $sale ? $sale->invoice_no.' — '.$sale->customer->name : '—';
            }),
            DateTimePicker::make('return_date')->label(__('Date'))->default(now())->required(),
            TextInput::make('reason')->label(__('Reason'))->maxLength(255),
            Repeater::make('lines')->label(__('Items'))->columnSpanFull()->addable(false)->deletable(false)->reorderable(false)
                ->table([TableColumn::make(__('Product')), TableColumn::make(__('Can be returned')), TableColumn::make(__('Return quantity')), TableColumn::make(__('Back to stock'))])
                ->schema([
                    Hidden::make('sale_item_id'),
                    TextInput::make('label')->disabled()->dehydrated(false),
                    TextInput::make('returnable')->disabled()->dehydrated(false),
                    TextInput::make('qty')->numeric()->minValue(0)->default(0)->maxValue(fn ($get) => (float) $get('returnable')),
                    Toggle::make('restock')->default(true),
                ]),
            Select::make('refund_method_id')->label(__('Refund now with'))->helperText(__('Leave empty to keep it as credit on the customer account.'))
                ->options(fn () => PaymentMethod::query()->where('is_active', true)->where('type', '!=', PaymentMethodType::Advance)->pluck('name', 'id')),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->columns(4)->components([
            Section::make()->columnSpanFull()->columns(4)->schema([
                TextEntry::make('number')->label(__('Number'))->weight('bold'),
                TextEntry::make('sale.invoice_no')->label(__('Invoice')),
                TextEntry::make('customer.name')->label(__('Customer')),
                TextEntry::make('return_date')->label(__('Date'))->dateTime(),
                TextEntry::make('payment_status')->label(__('Refund status'))->badge(),
                TextEntry::make('reason')->label(__('Reason'))->placeholder('—'),
                TextEntry::make('voided_at')->label(__('Voided'))->dateTime()->color('danger')->visible(fn ($record) => $record->voided_at !== null),
            ]),
            Section::make(__('Items'))->columnSpanFull()->schema([
                RepeatableEntry::make('items')->hiddenLabel()
                    ->table([EntryColumn::make(__('Product')), EntryColumn::make(__('Quantity')), EntryColumn::make(__('Price')), EntryColumn::make(__('Total')), EntryColumn::make(__('Back to stock'))])
                    ->schema([
                        TextEntry::make('variation_id')->formatStateUsing(fn ($state, $record) => $record->variation->fullName()),
                        TextEntry::make('qty')->numeric(2),
                        TextEntry::make('unit_price')->numeric(2),
                        TextEntry::make('line_total')->numeric(2),
                        TextEntry::make('restock')->formatStateUsing(fn ($state) => $state ? '✓' : '✗'),
                    ]),
            ]),
            Section::make(__('Totals'))->columnSpan(2)->columns(2)->schema([
                TextEntry::make('total')->label(__('Total'))->numeric(2)->weight('bold'),
                TextEntry::make('refunded_total')->label(__('Refunded'))->numeric(2),
                TextEntry::make('due_total')->label(__('Due to customer'))->numeric(2),
            ]),
            DocumentInfolist::payments(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['customer', 'sale']))
            ->defaultSort('return_date', 'desc')
            ->columns([
                TextColumn::make('number')->label(__('Number'))->searchable()->weight('bold')->description(fn ($record) => $record->voided_at ? __('Voided') : null),
                TextColumn::make('return_date')->label(__('Date'))->date()->sortable(),
                TextColumn::make('sale.invoice_no')->label(__('Invoice'))->searchable(),
                TextColumn::make('customer.name')->label(__('Customer'))->searchable(),
                TextColumn::make('total')->label(__('Total'))->numeric(2),
                TextColumn::make('payment_status')->label(__('Refund status'))->badge(),
            ])
            ->filters([Filter::make('hide_voided')->label(__('Hide voided'))->default()->query(fn (Builder $query) => $query->whereNull('voided_at'))])
            ->recordActions([ViewAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSaleReturns::route('/'),
            'create' => CreateSaleReturn::route('/create'),
            'view' => ViewSaleReturn::route('/{record}'),
        ];
    }
}
