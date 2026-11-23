<?php

namespace App\Filament\Admin\Resources\StockCounts\RelationManagers;

use App\Domain\Catalog\Models\Category;
use App\Domain\Inventory\Models\StockCount;
use App\Domain\Inventory\Models\StockCountItem;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\TextInputColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * The count sheet: each item with what the system expects and a box for the quantity on the shelf (saved as it is typed).
 * Search by name, code or barcode; filters for what is left, the shortages and the surpluses.
 */
class ItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Count sheet');
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    private function counting(): bool
    {
        $count = $this->getOwnerRecord();

        return $count instanceof StockCount && $count->isCounting();
    }

    public function table(Table $table): Table
    {
        $seeCost = (bool) auth()->user()?->can('products.view_purchase_price');

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['variation.product.category', 'variation.product.unit']))
            // items the system says are in the branch first, then the rest (they may still be on a shelf)
            ->defaultSort(fn (Builder $query) => $query->orderByRaw('case when expected_qty = 0 then 1 else 0 end')->orderBy('id'))
            ->paginated([25, 50, 100])
            ->defaultPaginationPageOption(50)
            ->columns([
                TextColumn::make('variation.product.name')->label(__('Product'))->wrap()
                    ->state(fn (StockCountItem $record) => $record->variation->fullName())
                    ->description(fn (StockCountItem $record) => $record->variation->product->category?->name)
                    ->searchable(query: fn (Builder $query, string $search) => $query->whereHas('variation', fn (Builder $v) => $v
                        ->where('sku', 'like', "%{$search}%")
                        ->orWhereHas('product', fn (Builder $p) => $p->where('name', 'like', "%{$search}%")->orWhere('sku', 'like', "%{$search}%"))
                        ->orWhereHas('barcodes', fn (Builder $b) => $b->where('barcode', $search)))),
                TextColumn::make('variation.sku')->label(__('Code'))->toggleable(),
                TextColumn::make('expected_qty')->label(__('System quantity'))->numeric(2)
                    ->suffix(fn (StockCountItem $record) => ' '.$record->variation->product->unit?->short_name),
                TextInputColumn::make('counted_qty')->label(__('Counted quantity'))->type('number')
                    ->state(fn (StockCountItem $record) => $record->counted_qty === null ? null : rtrim(rtrim((string) $record->counted_qty, '0'), '.'))
                    ->rules(['nullable', 'numeric', 'min:0'])
                    ->disabled(fn () => ! $this->counting())
                    ->updateStateUsing(function (StockCountItem $record, $state) {
                        $record->record($state);
                        $this->dispatch('stock-count-updated');

                        return $record->counted_qty;
                    }),
                TextColumn::make('difference')->label(__('Difference'))->numeric(2)->weight('bold')
                    ->state(fn (StockCountItem $record) => $record->counted_qty === null ? null : $record->difference)
                    ->placeholder('—')
                    ->color(fn ($state) => $state === null ? 'gray' : ((float) $state < 0 ? 'danger' : ((float) $state > 0 ? 'success' : 'gray'))),
                TextColumn::make('unit_cost')->label(__('Unit cost'))->numeric(2)->visible($seeCost)->toggleable(),
                TextColumn::make('difference_value')->label(__('Difference value'))->numeric(2)->visible($seeCost)->placeholder('—')
                    ->state(fn (StockCountItem $record) => $record->counted_qty === null ? null : (float) $record->difference * (float) $record->unit_cost)
                    ->color(fn ($state) => $state === null ? 'gray' : ((float) $state < 0 ? 'danger' : ((float) $state > 0 ? 'success' : 'gray'))),
            ])
            ->filters([
                SelectFilter::make('state')->label(__('Show'))->options([
                    'left' => __('Not counted yet'),
                    'counted' => __('Counted'),
                    'shortage' => __('Shortages'),
                    'surplus' => __('Surpluses'),
                    'match' => __('Matching'),
                ])->query(fn (Builder $query, array $data) => match ($data['value'] ?? null) {
                    'left' => $query->whereNull('counted_qty'),
                    'counted' => $query->whereNotNull('counted_qty'),
                    'shortage' => $query->whereNotNull('counted_qty')->where('difference', '<', 0),
                    'surplus' => $query->whereNotNull('counted_qty')->where('difference', '>', 0),
                    'match' => $query->whereNotNull('counted_qty')->where('difference', 0),
                    default => $query,
                }),
                SelectFilter::make('category')->label(__('Category'))
                    ->options(fn () => Category::query()->orderBy('name')->pluck('name', 'id'))
                    ->query(fn (Builder $query, array $data) => $query->when($data['value'] ?? null, fn (Builder $q, $id) => $q
                        ->whereHas('variation.product', fn (Builder $p) => $p->where('category_id', $id)))),
                SelectFilter::make('in_stock')->label(__('System quantity'))->options([
                    'positive' => __('Has stock'), 'zero' => __('No stock'), 'negative' => __('Negative'),
                ])->query(fn (Builder $query, array $data) => match ($data['value'] ?? null) {
                    'positive' => $query->where('expected_qty', '>', 0),
                    'zero' => $query->where('expected_qty', 0),
                    'negative' => $query->where('expected_qty', '<', 0),
                    default => $query,
                }),
            ])
            ->headerActions([
                Action::make('matchRest')->label(__('The rest matches the system'))->icon('heroicon-o-check')->color('gray')
                    ->visible(fn () => $this->counting())
                    ->requiresConfirmation()
                    ->modalDescription(__('Every item not counted yet gets its system quantity as the counted one.'))
                    ->action(function () {
                        StockCountItem::query()->where('stock_count_id', $this->getOwnerRecord()->getKey())->whereNull('counted_qty')->update(['counted_qty' => DB::raw('expected_qty'), 'difference' => 0]);
                        $this->dispatch('stock-count-updated');
                    }),
            ])
            ->toolbarActions([
                BulkAction::make('matchSelected')->label(__('Matches the system'))->icon('heroicon-o-check')
                    ->visible(fn () => $this->counting())
                    ->action(function (Collection $records) {
                        $records->whereInstanceOf(StockCountItem::class)->each(fn (StockCountItem $item) => $item->record($item->expected_qty));
                        $this->dispatch('stock-count-updated');
                    })->deselectRecordsAfterCompletion(),
                BulkAction::make('clearSelected')->label(__('Clear the counted quantity'))->icon('heroicon-o-x-mark')->color('gray')
                    ->visible(fn () => $this->counting())
                    ->action(function (Collection $records) {
                        $records->whereInstanceOf(StockCountItem::class)->each(fn (StockCountItem $item) => $item->record(null));
                        $this->dispatch('stock-count-updated');
                    })->deselectRecordsAfterCompletion(),
            ]);
    }
}
