<?php

namespace App\Filament\Admin\Resources\Products\Tables;

use App\Domain\Catalog\Enums\ProductType;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Variation;
use App\Filament\Admin\Pages\PrintLabels;
use App\Filament\Support\Avatar;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

final class ProductsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->with(['defaultVariation', 'unit'])
                ->withSum('stockLevels as stock_qty', 'qty_on_hand'))
            ->defaultSort('id', 'desc')
            ->columns([
                Avatar::column(TextColumn::make('name'), square: true)->label(__('Name'))->searchable(['name', 'sku'])->sortable()
                    ->description(fn (Product $r) => $r->sku),
                TextColumn::make('type')->label(__('Type'))->badge()->toggleable(),
                TextColumn::make('category.name')->label(__('Category'))->placeholder('—')->toggleable(),
                TextColumn::make('brand.name')->label(__('Brand'))->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('defaultVariation.sell_price_inc_tax')->label(__('Selling price'))->numeric(2),
                TextColumn::make('defaultVariation.purchase_price')->label(__('Purchase price'))->numeric(2)
                    ->visible(fn () => auth()->user()?->can('products.view_purchase_price'))->toggleable(),
                TextColumn::make('stock_qty')->label(__('Stock'))->numeric(2)->sortable()
                    ->state(fn (Product $r) => $r->tracksStock() ? (float) $r->getAttribute('stock_qty') : null)->placeholder('—')
                    ->suffix(fn (Product $r) => ' '.$r->unit?->short_name)
                    ->color(fn (Product $r) => $r->alert_qty !== null && (float) $r->getAttribute('stock_qty') <= (float) $r->alert_qty ? 'danger' : null),
                IconColumn::make('is_active')->label(__('Active'))->boolean()->toggleable(),
            ])
            ->filters([
                SelectFilter::make('type')->label(__('Type'))->options(ProductType::class),
                SelectFilter::make('category_id')->label(__('Category'))->relationship('category', 'name')->searchable()->preload(),
                SelectFilter::make('brand_id')->label(__('Brand'))->relationship('brand', 'name')->searchable()->preload(),
                TernaryFilter::make('is_active')->label(__('Active')),
                Filter::make('low_stock')->label(__('Low stock'))
                    ->query(fn (Builder $query) => $query->whereNotNull('alert_qty')->where('track_stock', true)
                        ->whereRaw('(select coalesce(sum(sl.qty_on_hand), 0) from stock_levels sl join variations v on v.id = sl.variation_id where v.product_id = products.id) <= products.alert_qty')),
                TrashedFilter::make(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                DeleteAction::make(),
                RestoreAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('activate')->label(__('Activate'))->icon('heroicon-o-check-circle')
                        ->action(fn (Collection $records) => Product::query()->whereKey($records->modelKeys())->update(['is_active' => true]))
                        ->deselectRecordsAfterCompletion(),
                    BulkAction::make('deactivate')->label(__('Deactivate'))->icon('heroicon-o-x-circle')->color('gray')
                        ->action(fn (Collection $records) => Product::query()->whereKey($records->modelKeys())->update(['is_active' => false]))
                        ->deselectRecordsAfterCompletion(),
                    BulkAction::make('labels')->label(__('Print barcode labels'))->icon('heroicon-o-qr-code')->color('gray')
                        ->visible(fn () => PrintLabels::canAccess())
                        ->action(fn (Collection $records) => redirect(PrintLabels::getUrl(['variations' => Variation::query()
                            ->whereIn('product_id', $records->modelKeys())->pluck('id')->implode(',')]))),
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
