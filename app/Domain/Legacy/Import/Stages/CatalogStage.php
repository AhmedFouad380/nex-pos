<?php

namespace App\Domain\Legacy\Import\Stages;

use App\Domain\Catalog\Enums\ProductType;
use App\Domain\Catalog\Enums\TaxType;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Unit;
use App\Domain\Catalog\Models\Variation;
use App\Domain\Foundation\Models\Location;
use App\Domain\Legacy\Import\ImportContext;
use App\Domain\Legacy\Import\LegacyPackage;
use App\Domain\Legacy\Import\Stage;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;

/**
 * Item groups → categories, units, items (+ their unit row: prices and barcode) → products.
 * Barcodes: the manufacturer barcode when the old system had one, and always "<ItemId>@@@" — what the old
 * labels on the shelves encode — so every printed label keeps scanning.
 */
final class CatalogStage implements Stage
{
    public function name(): string
    {
        return 'catalog';
    }

    public function run(LegacyPackage $package, ImportContext $context): void
    {
        DB::transaction(function () use ($package, $context) {
            $this->units($package, $context);
            $this->categories($package, $context);
        });
        $this->items($package, $context);
    }

    private function units(LegacyPackage $package, ImportContext $context): void
    {
        foreach ($package->all('Unit') as $row) {
            $name = trim((string) ($row['NameA'] ?: $row['NameE'])) ?: 'قطعة';
            $unit = Unit::query()->find($context->newId('Unit', (string) $row['UnitId']))
                ?? Unit::query()->where('name', $name)->first()
                ?? (in_array($name, ['عدد', 'قطعة'], true) ? Unit::query()->where('name', 'قطعة')->first() : null)
                ?? Unit::query()->create(['name' => $name, 'short_name' => mb_substr($name, 0, 10)]);
            $context->remember('Unit', (string) $row['UnitId'], $unit);
        }
    }

    private function categories(LegacyPackage $package, ImportContext $context): void
    {
        $groups = collect($package->all('ItemGroup'))->sortBy(fn ($g) => (int) ($g['GroupLevel'] ?? 0));
        foreach ($groups as $row) {
            $category = Category::query()->find($context->newId('ItemGroup', (string) $row['GroupId'])) ?? new Category;
            $parent = filled($row['GroupIdParent'] ?? null) ? $context->newId('ItemGroup', (string) $row['GroupIdParent']) : null;
            $category->fill([
                'name' => trim((string) ($row['NameA'] ?: $row['NameE'])) ?: '#'.$row['GroupId'],
                'code' => trim((string) ($row['Code'] ?? '')) ?: null,
                'parent_id' => $parent,
                'sort' => (int) ($row['GroupIndex'] ?? 0),
            ])->save();
            $context->remember('ItemGroup', (string) $row['GroupId'], $category);
            $context->count($this->name(), 'categories');
        }
    }

    private function items(LegacyPackage $package, ImportContext $context): void
    {
        $units = collect($package->all('ItemUnit'))->groupBy(fn ($u) => (string) $u['ItemId']);
        $locations = Location::query()->pluck('id')->all();
        $defaultUnit = $context->newId('Unit', '1') ?? Unit::query()->value('id');
        $total = $package->count('Item');
        $done = 0;

        foreach (array_chunk($package->all('Item'), 300) as $chunk) {
            DB::transaction(function () use ($chunk, $units, $locations, $defaultUnit, $context) {
                foreach ($chunk as $row) {
                    $itemId = (string) $row['ItemId'];
                    $unitRow = $units->get($itemId, collect())->sortByDesc(fn ($u) => (bool) $u['MainUnit'])->first() ?? [];
                    $sku = trim((string) $row['ItemCode']) ?: 'L'.$itemId;
                    $product = Product::query()->find($context->newId('Item', $itemId));
                    if (! $product && Product::query()->where('sku', $sku)->exists()) {
                        $sku .= '-'.$itemId;
                    }
                    $product ??= new Product(['sku' => $sku, 'type' => ProductType::Single]);
                    $product->fill([
                        'name' => trim((string) ($row['NameA'] ?: $row['NameE'])) ?: $sku,
                        'unit_id' => $context->newId('Unit', (string) ($unitRow['UnitId'] ?? '1')) ?? $defaultUnit,
                        'category_id' => $context->newId('ItemGroup', (string) $row['GroupId']),
                        'tax_type' => TaxType::Inclusive,
                        'track_stock' => (int) $row['ItemType'] === 1,
                        'alert_qty' => Decimal::of($row['Minimum'] ?: $row['Reorder'] ?: 0)->isPositive() ? Decimal::toDb($row['Minimum'] ?: $row['Reorder']) : null,
                        'is_active' => ! (bool) ($row['HideItem'] ?? false),
                        'is_sellable' => true,
                        'description' => trim((string) ($row['Notes'] ?? '')) ?: null,
                    ])->save();
                    $product->locations()->syncWithoutDetaching($locations);

                    $cost = Decimal::toDb($unitRow['PurchaseValue'] ?? 0);
                    $price = Decimal::toDb($unitRow['SalesValue'] ?? 0);
                    $variation = Variation::query()->firstOrNew(['product_id' => $product->id, 'is_default' => true]);
                    $variation->fill([
                        'name' => 'DEFAULT', 'sku' => $variation->sku ?? $product->sku,
                        'purchase_price' => $cost, 'purchase_price_inc_tax' => $cost, 'sell_price' => $price, 'sell_price_inc_tax' => $price,
                    ])->save();
                    foreach (array_filter([trim((string) ($unitRow['BarCode'] ?? '')), $itemId.'@@@']) as $barcode) {
                        if (! DB::table('variation_barcodes')->where('barcode', $barcode)->exists()) {
                            $variation->barcodes()->create(['barcode' => $barcode]);
                        }
                    }

                    $context->remember('Item', $itemId, $product);
                    $context->remember('ItemVariation', $itemId, $variation);
                    $context->count($this->name(), 'items');
                }
            });
            $done += count($chunk);
            $context->progress($this->name(), $done, $total);
        }
    }
}
