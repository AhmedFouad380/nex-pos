<?php

namespace App\Filament\Admin\Resources\PurchaseOrders;

use App\Domain\Purchasing\Enums\PurchaseOrderStatus;
use App\Domain\Purchasing\Models\PurchaseOrder;
use App\Filament\Admin\Resources\PurchaseOrders\Pages\CreatePurchaseOrder;
use App\Filament\Admin\Resources\PurchaseOrders\Pages\ListPurchaseOrders;
use App\Filament\Admin\Resources\PurchaseOrders\Pages\ViewPurchaseOrder;
use App\Filament\Admin\Resources\Purchases\Schemas\PurchaseForm;
use App\Filament\Concerns\AuthorizesWithPermissions;
use App\Filament\Concerns\HasNavigationCount;
use App\Filament\Concerns\ScopesToUserLocations;
use App\Filament\NavigationGroup;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PurchaseOrderResource extends Resource
{
    use AuthorizesWithPermissions, ScopesToUserLocations;
    use HasNavigationCount;

    protected static ?string $model = PurchaseOrder::class;

    protected static string|\UnitEnum|null $navigationGroup = NavigationGroup::Purchasing;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static ?int $navigationSort = 20;

    protected static ?string $recordTitleAttribute = 'number';

    public static function getModelLabel(): string
    {
        return __('Purchase order');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Purchase orders');
    }

    protected static function permissionFor(string $action): string|false
    {
        return in_array($action, ['viewAny', 'view', 'create'], true) ? 'purchase_orders.manage' : false;
    }

    public static function form(Schema $schema): Schema
    {
        return PurchaseForm::configure($schema, isOrder: true);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columnSpanFull()->columns(4)->schema([
                TextEntry::make('number')->label(__('Number'))->weight('bold'),
                TextEntry::make('supplier.name')->label(__('Supplier')),
                TextEntry::make('order_date')->label(__('Date'))->dateTime(),
                TextEntry::make('status')->label(__('Status'))->badge(),
                TextEntry::make('location.name')->label(__('Location')),
                TextEntry::make('expected_at')->label(__('Expected delivery'))->date()->placeholder('—'),
                TextEntry::make('total')->label(__('Total'))->numeric(2)->weight('bold'),
                TextEntry::make('note')->label(__('Note'))->placeholder('—'),
            ]),
            Section::make(__('Items'))->columnSpanFull()->schema([
                RepeatableEntry::make('items')->hiddenLabel()
                    ->table([TableColumn::make(__('Product')), TableColumn::make(__('Quantity')), TableColumn::make(__('Quantity received')),
                        TableColumn::make(__('Unit cost')), TableColumn::make(__('Total'))])
                    ->schema([
                        TextEntry::make('variation_id')->formatStateUsing(fn ($state, $record) => $record->variation->fullName()),
                        TextEntry::make('qty')->numeric(2)->suffix(fn ($record) => ' '.$record->unit->short_name),
                        TextEntry::make('qty_received')->numeric(2),
                        TextEntry::make('unit_cost')->numeric(2),
                        TextEntry::make('line_total')->numeric(2),
                    ]),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['supplier', 'location']))
            ->defaultSort('order_date', 'desc')
            ->columns([
                TextColumn::make('number')->label(__('Number'))->searchable()->weight('bold'),
                TextColumn::make('order_date')->label(__('Date'))->date()->sortable(),
                TextColumn::make('supplier.name')->label(__('Supplier'))->searchable(),
                TextColumn::make('expected_at')->label(__('Expected delivery'))->date()->placeholder('—'),
                TextColumn::make('status')->label(__('Status'))->badge(),
                TextColumn::make('total')->label(__('Total'))->numeric(2),
            ])
            ->filters([SelectFilter::make('status')->label(__('Status'))->options(PurchaseOrderStatus::class)])
            ->recordActions([ViewAction::make()]);
    }

    protected static function navigationCount(Builder $query): Builder
    {
        return $query->whereIn('status', [PurchaseOrderStatus::Sent, PurchaseOrderStatus::Partial]);
    }

    protected static function navigationCountColor(): string
    {
        return 'info';
    }

    protected static function navigationCountTooltip(): string
    {
        return __('Orders waiting for goods');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPurchaseOrders::route('/'),
            'create' => CreatePurchaseOrder::route('/create'),
            'view' => ViewPurchaseOrder::route('/{record}'),
        ];
    }
}
