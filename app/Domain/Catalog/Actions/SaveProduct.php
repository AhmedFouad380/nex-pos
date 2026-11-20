<?php

namespace App\Domain\Catalog\Actions;

use App\Domain\Catalog\Enums\ProductType;
use App\Domain\Catalog\Enums\TaxType;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\TaxRate;
use App\Domain\Catalog\Models\Variation;
use App\Domain\Catalog\Models\VariationBarcode;
use App\Settings\InventorySettings;
use App\Support\Decimal;
use App\Support\DomainException;
use Brick\Math\BigDecimal;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Creates or updates a product with everything hanging off it (variations, barcodes,
 * price-group prices, combo components, sub-units, locations) in one transaction.
 *
 * Prices: `purchase_price` is entered excluding tax; `sell_price` is entered the way the product's
 * tax_type says (inclusive or exclusive). Both tax-inclusive and exclusive values are stored.
 *
 * Expected $data (Filament form state):
 *   product columns..., sub_unit_ids[], location_ids[],
 *   single/service/combo: purchase_price, sell_price, barcodes[], group_prices[{price_group_id, value}],
 *   combo: combo_items[{component_variation_id, unit_id, qty}],
 *   variable: variations[{id?, name, sku?, attribute_values?, purchase_price, sell_price, barcodes[]}]
 */
final class SaveProduct
{
    private const PRODUCT_COLUMNS = [
        'name', 'sku', 'type', 'unit_id', 'secondary_unit_id', 'brand_id', 'category_id', 'tax_rate_id', 'tax_type',
        'barcode_type', 'track_stock', 'alert_qty', 'reorder_qty', 'track_lots', 'track_expiry', 'expiry_period',
        'expiry_unit', 'track_serials', 'warranty_id', 'weight', 'is_sellable', 'is_active', 'preparation_minutes',
        'description', 'custom_fields',
    ];

    public function __construct(private readonly InventorySettings $settings) {}

    public function handle(array $data, ?Product $product = null): Product
    {
        // the type field is disabled on edit (not submitted), so fall back to the stored type
        $rawType = $data['type'] ?? $product->type ?? ProductType::Single;
        $type = $rawType instanceof ProductType ? $rawType : ProductType::from($rawType);

        return DB::transaction(function () use ($data, $product, $type) {
            $attributes = Arr::only($data, self::PRODUCT_COLUMNS);
            $attributes['type'] = $type;
            if (in_array($type, [ProductType::Service, ProductType::Combo], true)) {
                $attributes['track_stock'] = false;
            }

            $product ??= new Product;
            $isNew = ! $product->exists;
            $attributes['sku'] = filled($attributes['sku'] ?? null) ? $attributes['sku'] : ($product->sku ?? 'TMP-'.uniqid());
            $product->fill($attributes);
            if ($isNew) {
                $product->created_by = auth()->id();
            }
            $product->save();

            if (str_starts_with($product->sku, 'TMP-')) {
                $product->update(['sku' => $this->settings->sku_prefix.str_pad((string) $product->id, 5, '0', STR_PAD_LEFT)]);
            }

            $product->units()->sync($data['sub_unit_ids'] ?? []);
            if (array_key_exists('location_ids', $data)) {
                $product->locations()->sync($data['location_ids'] ?? []);
            }

            $taxRate = $product->tax_rate_id ? Decimal::of(TaxRate::query()->whereKey($product->tax_rate_id)->value('rate')) : BigDecimal::zero();

            $type === ProductType::Variable
                ? $this->syncVariations($product, $data['variations'] ?? [], $taxRate)
                : $this->syncSingleVariation($product, $data, $taxRate);

            if ($type === ProductType::Combo) {
                $this->syncComboItems($product, $data['combo_items'] ?? []);
            }

            return $product->refresh();
        });
    }

    private function syncSingleVariation(Product $product, array $data, BigDecimal $taxRate): void
    {
        // a product switched from variable to single keeps only its default variation
        $variation = $product->variations()->where('is_default', true)->first()
            ?? $product->variations()->first()
            ?? new Variation(['product_id' => $product->id]);

        $variation->fill([
            'name' => 'DEFAULT',
            'attribute_values' => null,
            'sku' => $product->sku,
            'is_default' => true,
            ...$this->prices($product, $data['purchase_price'] ?? 0, $data['sell_price'] ?? 0, $taxRate),
        ])->save();

        $this->deleteVariations($product, [$variation->id]);

        $this->syncBarcodes($variation, $data['barcodes'] ?? []);
        $this->syncGroupPrices($variation, $data['group_prices'] ?? []);
    }

    private function syncVariations(Product $product, array $rows, BigDecimal $taxRate): void
    {
        if ($rows === []) {
            throw new DomainException(__('catalog.errors.variations_required'));
        }

        $keep = [];
        foreach (array_values($rows) as $i => $row) {
            $variation = isset($row['id']) ? $product->variations()->findOrFail($row['id']) : new Variation(['product_id' => $product->id]);
            $variation->fill([
                'name' => $row['name'],
                'attribute_values' => $row['attribute_values'] ?? null,
                'sku' => filled($row['sku'] ?? null) ? $row['sku'] : $product->sku.'-'.str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT),
                'is_default' => $i === 0,
                'sort' => $i,
                ...$this->prices($product, $row['purchase_price'] ?? 0, $row['sell_price'] ?? 0, $taxRate),
            ])->save();

            $this->syncBarcodes($variation, $row['barcodes'] ?? []);
            $keep[] = $variation->id;
        }

        $this->deleteVariations($product, $keep);
    }

    /** Soft-deletes the variations not in $keep (history stays intact) and frees their barcodes for reuse. */
    private function deleteVariations(Product $product, array $keep): void
    {
        $ids = $product->variations()->whereKeyNot($keep)->pluck('id');
        VariationBarcode::query()->whereIn('variation_id', $ids)->delete();
        Variation::query()->whereKey($ids)->delete();
    }

    /** @return array<string, string> price columns of a variation (purchase excl. tax; sell as entered per tax_type) */
    public function prices(Product $product, string|int|float|null $purchase, string|int|float|null $sell, BigDecimal $taxRate): array
    {
        $factor = BigDecimal::one()->plus(Decimal::divide($taxRate, 100, 6));
        $purchaseExc = Decimal::of($purchase);
        $sellEntered = Decimal::of($sell);

        [$sellExc, $sellInc] = $product->tax_type === TaxType::Inclusive
            ? [Decimal::divide($sellEntered, $factor), $sellEntered]
            : [$sellEntered, $sellEntered->multipliedBy($factor)];

        $margin = $purchaseExc->isPositive()
            ? Decimal::divide($sellExc->minus($purchaseExc)->multipliedBy(100), $purchaseExc)
            : BigDecimal::zero();

        return [
            'purchase_price' => Decimal::toDb($purchaseExc),
            'purchase_price_inc_tax' => Decimal::toDb($purchaseExc->multipliedBy($factor)),
            'profit_margin' => Decimal::toDb($margin),
            'sell_price' => Decimal::toDb($sellExc),
            'sell_price_inc_tax' => Decimal::toDb($sellInc),
        ];
    }

    /** @param list<string> $barcodes */
    private function syncBarcodes(Variation $variation, array $barcodes): void
    {
        $barcodes = array_values(array_unique(array_filter(array_map('trim', $barcodes))));

        $taken = VariationBarcode::query()->whereIn('barcode', $barcodes)->where('variation_id', '!=', $variation->id)
            ->with('variation.product')->first();
        if ($taken) {
            throw new DomainException(__('catalog.errors.barcode_taken', ['barcode' => $taken->barcode, 'item' => $taken->variation->fullName()]));
        }

        $variation->barcodes()->whereNotIn('barcode', $barcodes)->delete();
        foreach ($barcodes as $barcode) {
            $variation->barcodes()->firstOrCreate(['barcode' => $barcode]);
        }
    }

    private function syncGroupPrices(Variation $variation, array $rows): void
    {
        $keep = [];
        foreach ($rows as $row) {
            if (blank($row['price_group_id'] ?? null) || blank($row['value'] ?? null)) {
                continue;
            }
            $variation->prices()->updateOrCreate(
                ['price_group_id' => $row['price_group_id']],
                ['price_type' => $row['price_type'] ?? 'fixed', 'value' => Decimal::toDb($row['value'])],
            );
            $keep[] = (int) $row['price_group_id'];
        }
        $variation->prices()->whereNotIn('price_group_id', $keep)->delete();
    }

    private function syncComboItems(Product $product, array $rows): void
    {
        if ($rows === []) {
            throw new DomainException(__('catalog.errors.combo_items_required'));
        }
        $combo = $product->variations()->where('is_default', true)->firstOrFail();
        $combo->comboItems()->delete();

        foreach ($rows as $row) {
            $component = Variation::query()->with('product')->findOrFail($row['component_variation_id']);
            if ($component->product_id === $product->id || $component->product->type === ProductType::Combo) {
                throw new DomainException(__('catalog.errors.invalid_combo_item', ['item' => $component->fullName()]));
            }
            $combo->comboItems()->create([
                'component_variation_id' => $component->id,
                'unit_id' => $row['unit_id'] ?? $component->product->unit_id,
                'qty' => Decimal::toDb($row['qty']),
            ]);
        }
    }

    /** Form state for editing an existing product (inverse of handle()). */
    public static function formState(Product $product): array
    {
        $product->loadMissing(['variations.barcodes', 'variations.prices', 'variations.comboItems', 'units', 'locations']);
        $state = Arr::only($product->attributesToArray(), self::PRODUCT_COLUMNS);
        $state['type'] = $product->type;
        $state['tax_type'] = $product->tax_type;
        $state['sub_unit_ids'] = $product->units->pluck('id')->all();
        $state['location_ids'] = $product->locations->pluck('id')->all();

        $sellShown = fn (Variation $v) => (float) ($product->tax_type === TaxType::Inclusive ? $v->sell_price_inc_tax : $v->sell_price);

        if ($product->type === ProductType::Variable) {
            $state['variations'] = $product->variations->sortBy('sort')->map(fn (Variation $v) => [
                'id' => $v->id, 'name' => $v->name, 'sku' => $v->sku, 'attribute_values' => $v->attribute_values,
                'purchase_price' => (float) $v->purchase_price, 'sell_price' => $sellShown($v),
                'barcodes' => $v->barcodes->pluck('barcode')->all(),
            ])->values()->all();
        } else {
            $v = $product->variations->firstWhere('is_default', true) ?? $product->variations->first();
            $state['purchase_price'] = (float) $v?->purchase_price;
            $state['sell_price'] = $v ? $sellShown($v) : 0;
            $state['barcodes'] = $v?->barcodes->pluck('barcode')->all() ?? [];
            $state['group_prices'] = $v?->prices->map(fn ($p) => ['price_group_id' => $p->price_group_id, 'price_type' => $p->price_type->value, 'value' => (float) $p->value])->all() ?? [];
            $state['combo_items'] = $v?->comboItems->map(fn ($c) => ['component_variation_id' => $c->component_variation_id, 'unit_id' => $c->unit_id, 'qty' => (float) $c->qty])->all() ?? [];
        }

        return $state;
    }
}
