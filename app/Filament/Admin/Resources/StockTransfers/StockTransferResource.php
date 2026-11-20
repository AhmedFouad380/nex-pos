<?php

namespace App\Filament\Admin\Resources\StockTransfers;

use App\Domain\Foundation\Models\Location;
use App\Domain\Inventory\Enums\StockTransferStatus;
use App\Domain\Inventory\Models\StockTransfer;
use App\Filament\Admin\Resources\StockTransfers\Pages\CreateStockTransfer;
use App\Filament\Admin\Resources\StockTransfers\Pages\ListStockTransfers;
use App\Filament\Admin\Resources\StockTransfers\Pages\ViewStockTransfer;
use App\Filament\Components\StockLinesRepeater;
use App\Filament\Concerns\AuthorizesWithPermissions;
use App\Filament\Concerns\HasNavigationCount;
use App\Filament\Concerns\ScopesToUserLocations;
use App\Filament\NavigationGroup;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class StockTransferResource extends Resource
{
    use AuthorizesWithPermissions, ScopesToUserLocations;
    use HasNavigationCount;

    protected static ?string $model = StockTransfer::class;

    /** @var list<string> a user sees transfers leaving OR arriving at their locations */
    protected static array $locationColumns = ['from_location_id', 'to_location_id'];

    protected static string|\UnitEnum|null $navigationGroup = NavigationGroup::Inventory;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    protected static ?int $navigationSort = 30;

    protected static ?string $recordTitleAttribute = 'number';

    public static function getModelLabel(): string
    {
        return __('Stock transfer');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Stock transfers');
    }

    protected static function permissionFor(string $action): string|false
    {
        return match ($action) {
            'viewAny', 'view' => 'stock_transfers.view',
            'create' => 'stock_transfers.create',
            default => false,
        };
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(3)->components([
            Select::make('from_location_id')->label(__('From location'))->options(fn () => static::userLocationOptions())
                ->default(fn () => array_key_first(static::userLocationOptions()))->required()->live(),
            Select::make('to_location_id')->label(__('To location'))->required()->different('from_location_id')
                ->options(fn (Get $get) => Location::query()->where('is_active', true)->whereKeyNot($get('from_location_id') ?? 0)->pluck('name', 'id')),
            DateTimePicker::make('transfer_date')->label(__('Date'))->default(now())->required(),
            TextInput::make('shipping_cost')->label(__('Shipping cost'))->numeric()->minValue(0),
            Toggle::make('send_now')->label(__('Send immediately (stock leaves the source now)'))->default(true)->columnSpan(2)->inline(false),
            StockLinesRepeater::make('items', withCost: false),
            Textarea::make('note')->label(__('Note'))->columnSpanFull()->rows(2),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columnSpanFull()->columns(4)->schema([
                TextEntry::make('number')->label(__('Number'))->weight('bold')->copyable(),
                TextEntry::make('status')->label(__('Status'))->badge(),
                TextEntry::make('fromLocation.name')->label(__('From location')),
                TextEntry::make('toLocation.name')->label(__('To location')),
                TextEntry::make('transfer_date')->label(__('Date'))->dateTime(),
                TextEntry::make('sent_at')->label(__('Sent at'))->dateTime()->placeholder('—'),
                TextEntry::make('received_at')->label(__('Received at'))->dateTime()->placeholder('—'),
                TextEntry::make('receiver.name')->label(__('Received by'))->placeholder('—'),
                TextEntry::make('total_cost')->label(__('Total cost'))->numeric(2),
                TextEntry::make('shipping_cost')->label(__('Shipping cost'))->numeric(2),
                TextEntry::make('creator.name')->label(__('By'))->placeholder('—'),
                TextEntry::make('note')->label(__('Note'))->columnSpanFull()->placeholder('—'),
            ]),
            Section::make(__('Items'))->columnSpanFull()->schema([
                RepeatableEntry::make('items')->hiddenLabel()
                    ->table([
                        TableColumn::make(__('Product')), TableColumn::make(__('Unit')), TableColumn::make(__('Quantity sent')),
                        TableColumn::make(__('Quantity received')), TableColumn::make(__('Unit cost')),
                    ])
                    ->schema([
                        TextEntry::make('variation_id')->formatStateUsing(fn ($state, $record) => $record->variation->fullName()),
                        TextEntry::make('unit.name'),
                        TextEntry::make('qty_sent')->numeric(2),
                        TextEntry::make('qty_received')->numeric(2),
                        TextEntry::make('unit_cost')->numeric(2),
                    ]),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['fromLocation', 'toLocation']))
            ->defaultSort('transfer_date', 'desc')
            ->columns([
                TextColumn::make('number')->label(__('Number'))->searchable()->weight('bold'),
                TextColumn::make('transfer_date')->label(__('Date'))->dateTime()->sortable(),
                TextColumn::make('fromLocation.name')->label(__('From location')),
                TextColumn::make('toLocation.name')->label(__('To location')),
                TextColumn::make('status')->label(__('Status'))->badge(),
                TextColumn::make('total_cost')->label(__('Total cost'))->numeric(2),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('Status'))->options(StockTransferStatus::class),
                SelectFilter::make('from_location_id')->label(__('From location'))->options(fn () => Location::query()->pluck('name', 'id')),
                SelectFilter::make('to_location_id')->label(__('To location'))->options(fn () => Location::query()->pluck('name', 'id')),
            ])
            ->recordActions([ViewAction::make()]);
    }

    /** Sent and not yet received — someone has to receive them. */
    protected static function navigationCount(Builder $query): Builder
    {
        return $query->where('status', StockTransferStatus::InTransit);
    }

    protected static function navigationCountTooltip(): string
    {
        return __('Transfers on the way');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStockTransfers::route('/'),
            'create' => CreateStockTransfer::route('/create'),
            'view' => ViewStockTransfer::route('/{record}'),
        ];
    }
}
