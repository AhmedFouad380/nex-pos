<?php

namespace App\Filament\Components;

use App\Domain\Catalog\Enums\ProductType;
use App\Domain\Catalog\Models\Variation;
use Filament\Forms\Components\Select;

/** Searchable product/variation picker (name, SKU or barcode). Reused by every document line form. */
final class VariationSelect
{
    public static function make(string $name = 'variation_id', bool $stockTrackedOnly = false): Select
    {
        return Select::make($name)
            ->label(__('Product'))
            ->searchable()
            ->getSearchResultsUsing(fn (string $search) => self::search($search, $stockTrackedOnly))
            ->getOptionLabelUsing(fn ($value) => Variation::query()->with('product')->find($value)?->fullName())
            ->required();
    }

    /** @return array<int, string> */
    public static function search(string $search, bool $stockTrackedOnly = false, int $limit = 50): array
    {
        $search = trim($search);

        return Variation::query()
            ->with('product')
            ->whereHas('product', function ($q) use ($stockTrackedOnly) {
                $q->where('is_active', true)
                    ->when($stockTrackedOnly, fn ($q) => $q->where('track_stock', true)
                        ->whereNotIn('type', [ProductType::Service, ProductType::Combo]));
            })
            ->where(fn ($q) => $q
                ->where('sku', 'like', "%{$search}%")
                ->orWhereHas('barcodes', fn ($b) => $b->where('barcode', $search))
                ->orWhereHas('product', fn ($p) => $p->where('name', 'like', "%{$search}%")->orWhere('sku', 'like', "%{$search}%")))
            ->limit($limit)
            ->get()
            ->mapWithKeys(fn (Variation $v) => [$v->id => $v->fullName().' ('.$v->sku.')'])
            ->all();
    }
}
