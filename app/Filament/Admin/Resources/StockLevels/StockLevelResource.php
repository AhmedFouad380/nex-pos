<?php

namespace App\Filament\Admin\Resources\StockLevels;

use App\Domain\Catalog\Models\Category;
use App\Domain\Inventory\Models\StockLevel;
use App\Filament\Admin\Resources\Products\ProductResource;
use App\Filament\Admin\Resources\StockLevels\Pages\ListStockLevels;
use App\Filament\Concerns\AuthorizesWithPermissions;
use App\Filament\Concerns\HasNavigationCount;
use App\Filament\Concerns\ScopesToUserLocations;
use App\Filament\NavigationGroup;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\Summarizers\Summarizer;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/** Read-only stock report: on hand, average cost and value per item × location. */
class StockLevelResource extends Resource
{
    use AuthorizesWithPermissions, ScopesToUserLocations;
    use HasNavigationCount;

    protected static ?string $model = StockLevel::class;

    protected static string|\UnitEnum|null $navigationGroup = NavigationGroup::Inventory;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedArchiveBox;

    protected static ?int $navigationSort = 10;

    public static function getModelLabel(): string
    {
        return __('Stock level');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Stock on hand');
    }

    protected static function permissionFor(string $action): string|false
    {
        return in_array($action, ['viewAny', 'view'], true) ? 'stock.view' : false;
    }

    public static function table(Table $table): Table
    {
        $canSeeCost = fn () => auth()->user()?->can('products.view_purchase_price') ?? false;

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->with(['variation.product.unit', 'location'])
                ->select('stock_levels.*', DB::raw('stock_levels.qty_on_hand * stock_levels.avg_cost as stock_value')))
            ->defaultSort('qty_on_hand', 'desc')
            ->columns([
                TextColumn::make('variation.product.name')->label(__('Product'))
                    ->formatStateUsing(fn ($state, StockLevel $r) => $r->variation->fullName())
                    ->description(fn (StockLevel $r) => $r->variation->sku)
                    ->searchable(query: fn (Builder $query, string $search) => $query->whereHas('variation', fn ($v) => $v
                        ->where('sku', 'like', "%{$search}%")
                        ->orWhereHas('product', fn ($p) => $p->where('name', 'like', "%{$search}%"))))
                    ->url(fn (StockLevel $r) => ProductResource::getUrl('view', ['record' => $r->variation->product_id])),
                TextColumn::make('location.name')->label(__('Location'))->sortable(),
                TextColumn::make('qty_on_hand')->label(__('On hand'))->numeric(2)->sortable()
                    ->suffix(fn (StockLevel $r) => ' '.$r->variation->product->unit?->short_name)
                    ->color(fn (StockLevel $r) => (float) $r->qty_on_hand < 0 ? 'danger'
                        : ($r->variation->product->alert_qty !== null && (float) $r->qty_on_hand <= (float) $r->variation->product->alert_qty ? 'warning' : null))
                    ->summarize(Sum::make()->label(__('Total quantity'))->numeric(2)),
                TextColumn::make('avg_cost')->label(__('Average cost'))->numeric(2)->visible($canSeeCost),
                TextColumn::make('stock_value')->label(__('Stock value'))->numeric(2)->sortable()->visible($canSeeCost)
                    ->summarize(Summarizer::make()->label(__('Total value'))->numeric(2)
                        ->using(fn ($query) => $query->sum(DB::raw('qty_on_hand * avg_cost')))),
                TextColumn::make('updated_at')->label(__('Last movement'))->since()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('location_id')->label(__('Location'))->options(fn () => static::userLocationOptions()),
                SelectFilter::make('category')->label(__('Category'))
                    ->options(fn () => Category::query()->pluck('name', 'id'))
                    ->query(fn (Builder $query, array $data) => $query->when($data['value'] ?? null,
                        fn ($q, $id) => $q->whereHas('variation.product', fn ($p) => $p->where('category_id', $id)))),
                Filter::make('hide_zero')->label(__('Hide zero stock'))->default()
                    ->query(fn (Builder $query) => $query->where('qty_on_hand', '!=', 0)),
                Filter::make('low')->label(__('Low stock'))
                    ->query(fn (Builder $query) => $query->whereHas('variation.product', fn ($p) => $p
                        ->whereNotNull('alert_qty')->whereColumn('alert_qty', '>=', 'stock_levels.qty_on_hand'))),
                Filter::make('negative')->label(__('Negative stock'))->query(fn (Builder $query) => $query->where('qty_on_hand', '<', 0)),
            ]);
    }

    /** Items at or below their alert quantity. */
    protected static function navigationCount(Builder $query): Builder
    {
        return $query->whereHas('variation.product', fn ($q) => $q->whereNotNull('alert_qty')
            ->whereColumn('products.alert_qty', '>=', 'stock_levels.qty_on_hand'));
    }

    protected static function navigationCountColor(): string
    {
        return 'danger';
    }

    protected static function navigationCountTooltip(): string
    {
        return __('Low stock items');
    }

    public static function getPages(): array
    {
        return ['index' => ListStockLevels::route('/')];
    }
}
