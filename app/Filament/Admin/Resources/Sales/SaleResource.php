<?php

namespace App\Filament\Admin\Resources\Sales;

use App\Domain\Sales\Enums\SaleChannel;
use App\Domain\Sales\Enums\SaleStatus;
use App\Domain\Sales\Models\Sale;
use App\Domain\Shared\Enums\PaymentStatus;
use App\Filament\Admin\Resources\Sales\Pages\ListSales;
use App\Filament\Admin\Resources\Sales\Pages\ViewSale;
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

class SaleResource extends Resource
{
    use HasNavigationCount;
    use ScopesToUserLocations {
        getEloquentQuery as locationScopedQuery;
    }

    protected static ?string $model = Sale::class;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Sales;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptPercent;

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'invoice_no';

    public static function getModelLabel(): string
    {
        return __('Sale');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Sales invoices');
    }

    public static function getAuthorizationResponse(string|UnitEnum $action, ?Model $record = null): Response
    {
        $user = auth()->user();
        $allowed = in_array($action instanceof UnitEnum ? $action->name : $action, ['viewAny', 'view'], true)
            && ($user?->can('sales.view') || $user?->can('sales.view_own'));

        return $allowed ? Response::allow() : Response::deny();
    }

    public static function getEloquentQuery(): Builder
    {
        $query = static::locationScopedQuery();
        $user = auth()->user();
        if ($user instanceof User && ! $user->can('sales.view')) {
            $query->where('created_by', $user->id);
        }
        if ($user instanceof User && $user->can('sales.view_paid_only') && ! $user->isSuperAdmin()) {
            $query->where('payment_status', PaymentStatus::Paid);
        } elseif ($user instanceof User && $user->can('sales.view_due_only') && ! $user->isSuperAdmin()) {
            $query->where('payment_status', '!=', PaymentStatus::Paid);
        }

        return $query;
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->columns(4)->components([
            Section::make()->columnSpanFull()->columns(4)->schema([
                TextEntry::make('invoice_no')->label(__('Number'))->weight('bold')->copyable()
                    ->state(fn (Sale $r) => $r->invoice_no ?? $r->draft_no),
                TextEntry::make('status')->label(__('Status'))->badge(),
                TextEntry::make('return_state')->label(__('Returns'))->badge()
                    ->state(fn (Sale $r) => $r->returnState())
                    ->formatStateUsing(fn (string $state) => $state === 'full' ? __('Fully returned') : __('Partially returned'))
                    ->color(fn (string $state) => $state === 'full' ? 'danger' : 'warning')
                    ->icon('heroicon-m-arrow-uturn-left')
                    ->helperText(fn (Sale $r) => $r->returns()->whereNull('voided_at')->pluck('number')->implode('، '))
                    ->visible(fn (Sale $r) => $r->returnState() !== null),
                TextEntry::make('customer.name')->label(__('Customer')),
                TextEntry::make('sale_date')->label(__('Date'))->dateTime(),
                TextEntry::make('location.name')->label(__('Location')),
                TextEntry::make('channel')->label(__('Channel'))->badge(),
                TextEntry::make('payment_status')->label(__('Payment status'))->badge(),
                TextEntry::make('creator.name')->label(__('Cashier'))->placeholder('—'),
                TextEntry::make('voided_at')->label(__('Voided'))->dateTime()->color('danger')->visible(fn (Sale $r) => $r->voided_at !== null),
                TextEntry::make('void_reason')->label(__('Void reason'))->color('danger')->visible(fn (Sale $r) => $r->voided_at !== null),
                TextEntry::make('note')->label(__('Note'))->placeholder('—'),
            ]),
            Section::make(__('Items'))->columnSpanFull()->schema([
                RepeatableEntry::make('items')->hiddenLabel()
                    ->table([
                        TableColumn::make(__('Product')), TableColumn::make(__('Quantity')), TableColumn::make(__('Price')),
                        TableColumn::make(__('Discount')), TableColumn::make(__('Total')), TableColumn::make(__('Cost')), TableColumn::make(__('Returned')),
                    ])
                    ->schema([
                        TextEntry::make('variation_id')->formatStateUsing(fn ($state, $record) => $record->variation->fullName()),
                        TextEntry::make('qty')->numeric(2)->suffix(fn ($record) => ' '.$record->unit->short_name),
                        TextEntry::make('unit_price_inc_tax')->numeric(2),
                        TextEntry::make('discount_value')->numeric(2)
                            ->formatStateUsing(fn ($state, $record) => (float) $state ? (float) $state.($record->discount_type?->value === 'percent' ? '%' : '') : '—'),
                        TextEntry::make('line_total')->numeric(2),
                        TextEntry::make('cost_total')->numeric(2)->visible(fn () => auth()->user()?->can('products.view_purchase_price')),
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
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['customer', 'location', 'creator']))
            ->defaultSort('sale_date', 'desc')
            ->columns([
                TextColumn::make('invoice_no')->label(__('Number'))->weight('bold')
                    ->state(fn (Sale $r) => $r->invoice_no ?? $r->draft_no)
                    ->searchable(['invoice_no', 'draft_no'])
                    ->description(fn (Sale $r) => $r->voided_at ? __('Voided') : null),
                TextColumn::make('sale_date')->label(__('Date'))->dateTime()->sortable(),
                Avatar::column(TextColumn::make('customer.name'))->label(__('Customer'))->searchable(),
                TextColumn::make('status')->label(__('Status'))->badge(),
                TextColumn::make('total')->label(__('Total'))->numeric(2)->sortable(),
                TextColumn::make('due_total')->label(__('Due'))->numeric(2)->color(fn ($state) => (float) $state > 0 ? 'danger' : null),
                TextColumn::make('payment_status')->label(__('Payment status'))->badge(),
                TextColumn::make('creator.name')->label(__('Cashier'))->toggleable(),
                TextColumn::make('location.name')->label(__('Location'))->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('Status'))->options(SaleStatus::class)->default(SaleStatus::Final->value),
                SelectFilter::make('payment_status')->label(__('Payment status'))->options(PaymentStatus::class),
                SelectFilter::make('channel')->label(__('Channel'))->options(SaleChannel::class),
                SelectFilter::make('customer_id')->label(__('Customer'))->relationship('customer', 'name')->searchable(),
                SelectFilter::make('location_id')->label(__('Location'))->options(fn () => static::userLocationOptions()),
                Filter::make('today')->label(__('Today'))->query(fn (Builder $query) => $query->whereDate('sale_date', today())),
                Filter::make('hide_voided')->label(__('Hide voided'))->default()->query(fn (Builder $query) => $query->whereNull('voided_at')),
            ])
            ->recordActions([ViewAction::make()]);
    }

    /** Final invoices with money still to collect. */
    protected static function navigationCount(Builder $query): Builder
    {
        return $query->where('status', SaleStatus::Final)->whereNull('voided_at')->where('due_total', '>', 0);
    }

    protected static function navigationCountColor(): string
    {
        $overdue = once(fn () => static::navigationCount(static::getEloquentQuery())->whereDate('due_date', '<', now())->exists());

        return $overdue ? 'danger' : 'warning';
    }

    protected static function navigationCountTooltip(): string
    {
        return __('Invoices with an amount due');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSales::route('/'),
            'view' => ViewSale::route('/{record}'),
        ];
    }
}
