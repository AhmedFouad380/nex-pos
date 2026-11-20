<?php

namespace App\Filament\Admin\Resources\Purchases;

use App\Domain\Purchasing\Enums\PurchaseStatus;
use App\Domain\Purchasing\Models\Purchase;
use App\Domain\Shared\Enums\PaymentStatus;
use App\Filament\Admin\Resources\Purchases\Pages\CreatePurchase;
use App\Filament\Admin\Resources\Purchases\Pages\ListPurchases;
use App\Filament\Admin\Resources\Purchases\Pages\ViewPurchase;
use App\Filament\Admin\Resources\Purchases\Schemas\PurchaseForm;
use App\Filament\Concerns\HasNavigationCount;
use App\Filament\Concerns\ScopesToUserLocations;
use App\Filament\NavigationGroup;
use App\Filament\Support\Avatar;
use App\Filament\Support\DocumentInfolist;
use App\Models\User;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class PurchaseResource extends Resource
{
    use HasNavigationCount;
    use ScopesToUserLocations {
        getEloquentQuery as locationScopedQuery;
    }

    protected static ?string $model = Purchase::class;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Purchasing;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingCart;

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'number';

    public static function getModelLabel(): string
    {
        return __('Purchase');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Purchases');
    }

    /** View (all or own) and create only — purchases are voided, never edited or deleted. */
    public static function getAuthorizationResponse(string|UnitEnum $action, ?Model $record = null): Response
    {
        $user = auth()->user();
        $allowed = match ($action instanceof UnitEnum ? $action->name : $action) {
            'viewAny', 'view' => $user?->can('purchases.view') || $user?->can('purchases.view_own'),
            'create' => (bool) $user?->can('purchases.create'),
            default => false,
        };

        return $allowed ? Response::allow() : Response::deny();
    }

    public static function getEloquentQuery(): Builder
    {
        $query = static::locationScopedQuery();
        $user = auth()->user();

        return $user instanceof User && ! $user->can('purchases.view') ? $query->where('created_by', $user->id) : $query;
    }

    public static function form(Schema $schema): Schema
    {
        return PurchaseForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->columns(4)->components([
            Section::make()->columnSpanFull()->columns(4)->schema([
                TextEntry::make('number')->label(__('Number'))->weight('bold')->copyable(),
                TextEntry::make('supplier.name')->label(__('Supplier')),
                TextEntry::make('purchase_date')->label(__('Date'))->dateTime(),
                TextEntry::make('status')->label(__('Status'))->badge(),
                TextEntry::make('supplier_ref')->label(__('Supplier invoice no.'))->placeholder('—'),
                TextEntry::make('location.name')->label(__('Location')),
                TextEntry::make('due_date')->label(__('Due date'))->date()->placeholder('—'),
                TextEntry::make('payment_status')->label(__('Payment status'))->badge(),
                TextEntry::make('voided_at')->label(__('Voided'))->dateTime()->color('danger')->visible(fn (Purchase $r) => $r->voided_at !== null),
                TextEntry::make('void_reason')->label(__('Void reason'))->color('danger')->visible(fn (Purchase $r) => $r->voided_at !== null),
                TextEntry::make('purchaseOrder.number')->label(__('Purchase order'))->placeholder('—'),
                TextEntry::make('creator.name')->label(__('By'))->placeholder('—'),
            ]),
            Section::make(__('Items'))->columnSpanFull()->schema([
                RepeatableEntry::make('items')->hiddenLabel()
                    ->table([
                        TableColumn::make(__('Product')), TableColumn::make(__('Quantity')), TableColumn::make(__('Unit cost')),
                        TableColumn::make(__('Discount')), TableColumn::make(__('Tax')), TableColumn::make(__('Total')),
                        TableColumn::make(__('Landed cost')), TableColumn::make(__('Returned')),
                    ])
                    ->schema([
                        TextEntry::make('variation_id')->formatStateUsing(fn ($state, $record) => $record->variation->fullName()),
                        TextEntry::make('qty')->numeric(2)->suffix(fn ($record) => ' '.$record->unit->short_name),
                        TextEntry::make('unit_cost_before_discount')->numeric(2),
                        TextEntry::make('discount_value')->numeric(2)->suffix('%'),
                        TextEntry::make('tax_amount')->numeric(2),
                        TextEntry::make('line_total')->numeric(2),
                        TextEntry::make('landed_unit_cost')->numeric(4)->tooltip(__('Per base unit, after discount, shipping and costs')),
                        TextEntry::make('qty_returned')->numeric(2),
                    ]),
            ]),
            DocumentInfolist::totals(),
            DocumentInfolist::payments(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['supplier', 'location']))
            ->defaultSort('purchase_date', 'desc')
            ->columns([
                TextColumn::make('number')->label(__('Number'))->searchable()->weight('bold')
                    ->description(fn (Purchase $r) => $r->voided_at ? __('Voided') : $r->supplier_ref),
                TextColumn::make('purchase_date')->label(__('Date'))->date()->sortable(),
                Avatar::column(TextColumn::make('supplier.name'))->label(__('Supplier'))->searchable(),
                TextColumn::make('location.name')->label(__('Location'))->toggleable(),
                TextColumn::make('status')->label(__('Status'))->badge(),
                TextColumn::make('total')->label(__('Total'))->numeric(2)->sortable(),
                TextColumn::make('due_total')->label(__('Due'))->numeric(2)->sortable()->color(fn ($state) => (float) $state > 0 ? 'danger' : null),
                TextColumn::make('payment_status')->label(__('Payment status'))->badge(),
            ])
            ->filters([
                SelectFilter::make('supplier_id')->label(__('Supplier'))->relationship('supplier', 'name')->searchable(),
                SelectFilter::make('status')->label(__('Status'))->options(PurchaseStatus::class),
                SelectFilter::make('payment_status')->label(__('Payment status'))->options(PaymentStatus::class),
                SelectFilter::make('location_id')->label(__('Location'))->options(fn () => static::userLocationOptions()),
                Filter::make('hide_voided')->label(__('Hide voided'))->default()->query(fn (Builder $query) => $query->whereNull('voided_at')),
            ])
            ->recordActions([ViewAction::make()]);
    }

    protected static function navigationCount(Builder $query): Builder
    {
        return $query->whereNull('voided_at')->where('due_total', '>', 0);
    }

    protected static function navigationCountTooltip(): string
    {
        return __('Purchases not fully paid');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPurchases::route('/'),
            'create' => CreatePurchase::route('/create'),
            'view' => ViewPurchase::route('/{record}'),
        ];
    }
}
