<?php

namespace App\Filament\Admin\Resources\StockCounts;

use App\Domain\Catalog\Models\Brand;
use App\Domain\Catalog\Models\Category;
use App\Domain\Inventory\Enums\StockCountScope;
use App\Domain\Inventory\Enums\StockCountStatus;
use App\Domain\Inventory\Models\StockCount;
use App\Filament\Admin\Resources\StockAdjustments\StockAdjustmentResource;
use App\Filament\Concerns\AuthorizesWithPermissions;
use App\Filament\Concerns\ScopesToUserLocations;
use App\Filament\NavigationGroup;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
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

/**
 * Stock counts (الجرد): the branch's items with what the system expects, the quantity found on the shelves,
 * a report of the shortages and surpluses, and posting — the counted quantity becomes the stock.
 */
class StockCountResource extends Resource
{
    use AuthorizesWithPermissions, ScopesToUserLocations;

    protected static ?string $model = StockCount::class;

    protected static string|\UnitEnum|null $navigationGroup = NavigationGroup::Inventory;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static ?int $navigationSort = 25;

    protected static ?string $recordTitleAttribute = 'number';

    public static function getModelLabel(): string
    {
        return __('Stock count');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Stock counts');
    }

    protected static function permissionFor(string $action): string|false
    {
        return in_array($action, ['viewAny', 'view', 'create'], true) ? 'stock_counts.manage' : false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Select::make('location_id')->label(__('Location'))->options(fn () => static::userLocationOptions())
                ->default(fn () => array_key_first(static::userLocationOptions()))->required(),
            Select::make('scope')->label(__('Items to count'))->options(collect(StockCountScope::cases())
                ->reject(fn (StockCountScope $scope) => $scope === StockCountScope::Selected)
                ->mapWithKeys(fn (StockCountScope $scope) => [$scope->value => $scope->getLabel()]))
                ->default(StockCountScope::Full->value)->required()->live(),
            Select::make('category_ids')->label(__('Categories'))->multiple()->required()->columnSpanFull()
                ->options(fn () => Category::query()->orderBy('name')->pluck('name', 'id'))
                ->visible(fn (Get $get) => $get('scope') === StockCountScope::Category->value),
            Select::make('brand_ids')->label(__('Brands'))->multiple()->required()->columnSpanFull()
                ->options(fn () => Brand::query()->orderBy('name')->pluck('name', 'id'))
                ->visible(fn (Get $get) => $get('scope') === StockCountScope::Brand->value),
            Textarea::make('note')->label(__('Note'))->rows(2)->columnSpanFull(),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columnSpanFull()->columns(4)->schema([
                TextEntry::make('number')->label(__('Number'))->weight('bold'),
                TextEntry::make('location.name')->label(__('Location')),
                TextEntry::make('status')->label(__('Status'))->badge(),
                TextEntry::make('scope')->label(__('Items to count')),
                TextEntry::make('started_at')->label(__('Started at'))->dateTime(),
                TextEntry::make('posted_at')->label(__('Posted at'))->dateTime()->placeholder('—'),
                TextEntry::make('creator.name')->label(__('By'))->placeholder('—'),
                TextEntry::make('stockAdjustment.number')->label(__('Shortages adjustment'))->placeholder('—')->color('danger')
                    ->url(fn (StockCount $r) => $r->stock_adjustment_id ? StockAdjustmentResource::getUrl('view', ['record' => $r->stock_adjustment_id]) : null),
                TextEntry::make('surplusAdjustment.number')->label(__('Surpluses adjustment'))->placeholder('—')->color('success')
                    ->url(fn (StockCount $r) => $r->surplus_adjustment_id ? StockAdjustmentResource::getUrl('view', ['record' => $r->surplus_adjustment_id]) : null),
                TextEntry::make('note')->label(__('Note'))->placeholder('—')->columnSpan(3),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['location', 'creator'])
                ->withCount(['items', 'items as counted_count' => fn (Builder $items) => $items->whereNotNull('counted_qty')]))
            ->defaultSort('started_at', 'desc')
            ->columns([
                TextColumn::make('number')->label(__('Number'))->searchable()->weight('bold'),
                TextColumn::make('location.name')->label(__('Location')),
                TextColumn::make('status')->label(__('Status'))->badge(),
                TextColumn::make('scope')->label(__('Items to count')),
                TextColumn::make('progress')->label(__('Counted'))
                    ->state(fn (StockCount $r) => $r->getAttribute('counted_count').' / '.$r->getAttribute('items_count')),
                TextColumn::make('started_at')->label(__('Started at'))->dateTime()->sortable(),
                TextColumn::make('posted_at')->label(__('Posted at'))->dateTime()->placeholder('—'),
                TextColumn::make('creator.name')->label(__('By'))->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('Status'))->options(StockCountStatus::class),
                SelectFilter::make('location_id')->label(__('Location'))->options(fn () => static::userLocationOptions()),
            ])
            ->recordActions([ViewAction::make()]);
    }

    public static function getRelations(): array
    {
        return [RelationManagers\ItemsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListStockCounts::route('/'),
            'create' => Pages\CreateStockCount::route('/create'),
            'view' => Pages\ViewStockCount::route('/{record}'),
        ];
    }
}
