<?php

namespace App\Filament\Admin\Resources\Products\RelationManagers;

use App\Domain\Catalog\Models\Product;
use App\Domain\Inventory\Enums\StockMovementType;
use App\Domain\Inventory\Models\StockMovement;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Read-only stock card ("كارت الصنف"): every movement of every variation of the product. */
class StockMovementsRelationManager extends RelationManager
{
    protected static string $relationship = 'stockMovements';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Stock movements');
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord instanceof Product && $ownerRecord->tracksStock() && (auth()->user()?->can('stock.view') ?? false);
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => StockMovement::eagerLoadDocument($query)
                ->with(['location', 'variation', 'lot', 'creator']))
            ->defaultSort('moved_at', 'desc')
            ->columns([
                TextColumn::make('moved_at')->label(__('Date'))->dateTime()->sortable(),
                TextColumn::make('type')->label(__('Movement'))->badge(),
                TextColumn::make('document')->label(__('Document'))
                    ->state(fn (StockMovement $r) => $r->documentNumber())
                    ->description(fn (StockMovement $r) => $r->reverses_id ? __('Reversal') : null)->placeholder('—'),
                TextColumn::make('location.name')->label(__('Location')),
                TextColumn::make('variation.name')->label(__('Variation'))
                    ->formatStateUsing(fn ($state) => $state === 'DEFAULT' ? '—' : $state)->toggleable(),
                TextColumn::make('lot.number')->label(__('Lot'))->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('qty')->label(__('Quantity'))->numeric(2)
                    ->color(fn ($state) => (float) $state < 0 ? 'danger' : 'success'),
                TextColumn::make('unit_cost')->label(__('Unit cost'))->numeric(2)
                    ->visible(fn () => auth()->user()?->can('products.view_purchase_price')),
                TextColumn::make('creator.name')->label(__('By'))->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('type')->label(__('Movement'))->options(StockMovementType::class),
                SelectFilter::make('location_id')->label(__('Location'))->relationship('location', 'name'),
            ]);
    }
}
