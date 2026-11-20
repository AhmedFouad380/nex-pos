<?php

namespace App\Filament\Admin\Resources\PurchaseReturns;

use App\Domain\Catalog\Models\TaxRate;
use App\Domain\CRM\Enums\ContactType;
use App\Domain\CRM\Models\Contact;
use App\Domain\Purchasing\Models\Purchase;
use App\Domain\Purchasing\Models\PurchaseReturn;
use App\Filament\Admin\Resources\PurchaseReturns\Pages\CreatePurchaseReturn;
use App\Filament\Admin\Resources\PurchaseReturns\Pages\ListPurchaseReturns;
use App\Filament\Admin\Resources\PurchaseReturns\Pages\ViewPurchaseReturn;
use App\Filament\Components\StockLinesRepeater;
use App\Filament\Concerns\AuthorizesWithPermissions;
use App\Filament\Concerns\ScopesToUserLocations;
use App\Filament\NavigationGroup;
use App\Filament\Support\DocumentInfolist;
use App\Filament\Support\UserLocations;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn as EntryColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PurchaseReturnResource extends Resource
{
    use AuthorizesWithPermissions, ScopesToUserLocations;

    protected static ?string $model = PurchaseReturn::class;

    protected static string|\UnitEnum|null $navigationGroup = NavigationGroup::Purchasing;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUturnLeft;

    protected static ?int $navigationSort = 30;

    protected static ?string $recordTitleAttribute = 'number';

    public static function getModelLabel(): string
    {
        return __('Purchase return');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Purchase returns');
    }

    protected static function permissionFor(string $action): string|false
    {
        return match ($action) {
            'viewAny', 'view' => 'purchase_returns.view',
            'create' => 'purchase_returns.create',
            default => false,
        };
    }

    public static function form(Schema $schema): Schema
    {
        $fromPurchase = fn (Get $get) => filled($get('purchase_id'));

        return $schema->columns(3)->components([
            Hidden::make('purchase_id'),
            TextEntry::make('purchase_info')->label(__('Purchase'))->visible($fromPurchase)
                ->state(function (Get $get) {
                    $purchase = Purchase::query()->with('supplier')->find($get('purchase_id'));

                    return $purchase ? $purchase->number.' — '.$purchase->supplier->name : null;
                }),
            Select::make('supplier_id')->label(__('Supplier'))->required(fn (Get $get) => ! $fromPurchase($get))->hidden($fromPurchase)->searchable()
                ->options(fn () => Contact::query()->whereIn('type', [ContactType::Supplier, ContactType::Both])->orderBy('name')->limit(500)->pluck('name', 'id')),
            Select::make('location_id')->label(__('Location'))->required(fn (Get $get) => ! $fromPurchase($get))->hidden($fromPurchase)
                ->options(fn () => UserLocations::options())->default(fn () => UserLocations::first()),
            DateTimePicker::make('return_date')->label(__('Date'))->default(now())->required(),

            Repeater::make('purchase_lines')->label(__('Items'))->visible($fromPurchase)->columnSpanFull()
                ->addable(false)->deletable(false)->reorderable(false)
                ->table([TableColumn::make(__('Product')), TableColumn::make(__('Can be returned')), TableColumn::make(__('Return quantity'))])
                ->schema([
                    Hidden::make('purchase_item_id'),
                    TextInput::make('label')->disabled()->dehydrated(false),
                    TextInput::make('returnable')->disabled()->dehydrated(false),
                    TextInput::make('qty')->numeric()->minValue(0)->default(0)
                        ->maxValue(fn (Get $get) => (float) $get('returnable')),
                ]),

            StockLinesRepeater::make('items', withCost: true, withLots: false)->hidden($fromPurchase)
                ->label(__('Items')),
            Select::make('tax_rate_id')->label(__('Tax'))->hidden($fromPurchase)
                ->options(fn () => TaxRate::query()->where('is_active', true)->pluck('name', 'id')),
            Textarea::make('note')->label(__('Note'))->rows(2)->columnSpanFull(),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->columns(4)->components([
            Section::make()->columnSpanFull()->columns(4)->schema([
                TextEntry::make('number')->label(__('Number'))->weight('bold'),
                TextEntry::make('supplier.name')->label(__('Supplier')),
                TextEntry::make('return_date')->label(__('Date'))->dateTime(),
                TextEntry::make('purchase.number')->label(__('Purchase'))->placeholder('—'),
                TextEntry::make('location.name')->label(__('Location')),
                TextEntry::make('payment_status')->label(__('Refund status'))->badge(),
                TextEntry::make('voided_at')->label(__('Voided'))->dateTime()->color('danger')->visible(fn ($record) => $record->voided_at !== null),
                TextEntry::make('note')->label(__('Note'))->placeholder('—'),
            ]),
            Section::make(__('Items'))->columnSpanFull()->schema([
                RepeatableEntry::make('items')->hiddenLabel()
                    ->table([EntryColumn::make(__('Product')), EntryColumn::make(__('Quantity')), EntryColumn::make(__('Unit cost')), EntryColumn::make(__('Total'))])
                    ->schema([
                        TextEntry::make('variation_id')->formatStateUsing(fn ($state, $record) => $record->variation->fullName()),
                        TextEntry::make('qty')->numeric(2)->suffix(fn ($record) => ' '.$record->unit->short_name),
                        TextEntry::make('unit_cost')->numeric(2),
                        TextEntry::make('line_total')->numeric(2),
                    ]),
            ]),
            Section::make(__('Totals'))->columnSpan(2)->columns(2)->schema([
                TextEntry::make('total')->label(__('Total'))->numeric(2)->weight('bold'),
                TextEntry::make('paid_total')->label(__('Refunded'))->numeric(2),
                TextEntry::make('due_total')->label(__('Due from supplier'))->numeric(2),
            ]),
            DocumentInfolist::payments(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['supplier', 'purchase']))
            ->defaultSort('return_date', 'desc')
            ->columns([
                TextColumn::make('number')->label(__('Number'))->searchable()->weight('bold')
                    ->description(fn ($record) => $record->voided_at ? __('Voided') : null),
                TextColumn::make('return_date')->label(__('Date'))->date()->sortable(),
                TextColumn::make('supplier.name')->label(__('Supplier'))->searchable(),
                TextColumn::make('purchase.number')->label(__('Purchase'))->placeholder('—'),
                TextColumn::make('total')->label(__('Total'))->numeric(2),
                TextColumn::make('payment_status')->label(__('Refund status'))->badge(),
            ])
            ->filters([Filter::make('hide_voided')->label(__('Hide voided'))->default()->query(fn (Builder $query) => $query->whereNull('voided_at'))])
            ->recordActions([ViewAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPurchaseReturns::route('/'),
            'create' => CreatePurchaseReturn::route('/create'),
            'view' => ViewPurchaseReturn::route('/{record}'),
        ];
    }
}
