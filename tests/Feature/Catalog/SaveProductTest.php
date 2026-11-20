<?php

use App\Domain\Catalog\Actions\SaveProduct;
use App\Domain\Catalog\Enums\ProductType;
use App\Domain\Catalog\Enums\TaxType;
use App\Domain\Catalog\Models\PriceGroup;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\TaxRate;
use App\Domain\Catalog\Models\Unit;
use App\Support\DomainException;

beforeEach(function () {
    seedBaseData();
    $this->piece = Unit::query()->where('name', 'قطعة')->firstOrFail();
    $this->vat = TaxRate::query()->where('rate', 14)->firstOrFail();
    $this->base = ['name' => 'شاي', 'type' => 'single', 'unit_id' => $this->piece->id, 'tax_type' => TaxType::Inclusive];
});

it('creates a single product with an auto SKU, tax-split prices and barcodes', function () {
    $product = app(SaveProduct::class)->handle([...$this->base, 'tax_rate_id' => $this->vat->id,
        'purchase_price' => 100, 'sell_price' => 228, 'barcodes' => ['111', ' 111 ', '222']]);

    $v = $product->defaultVariation;
    expect($product->sku)->toBe('00001')
        ->and($v->sku)->toBe('00001')
        ->and($v->purchase_price_inc_tax)->toBe('114.0000')
        ->and($v->sell_price_inc_tax)->toBe('228.0000')
        ->and($v->sell_price)->toBe('200.0000')       // 228 / 1.14
        ->and($v->profit_margin)->toBe('100.0000')    // on tax-exclusive prices
        ->and($v->barcodes->pluck('barcode')->sort()->values()->all())->toBe(['111', '222']);
});

it('rejects a barcode already used by another product', function () {
    app(SaveProduct::class)->handle([...$this->base, 'sell_price' => 1, 'barcodes' => ['999']]);

    expect(fn () => app(SaveProduct::class)->handle([...$this->base, 'name' => 'قهوة', 'sell_price' => 1, 'barcodes' => ['999']]))
        ->toThrow(DomainException::class);
    expect(Product::query()->count())->toBe(1); // rolled back
});

it('creates, updates and removes variations of a variable product', function () {
    $product = app(SaveProduct::class)->handle([...$this->base, 'type' => 'variable', 'sku' => 'TSH', 'variations' => [
        ['name' => 'S', 'sell_price' => 100, 'barcodes' => ['S1']],
        ['name' => 'M', 'sell_price' => 110],
        ['name' => 'L', 'sell_price' => 120],
    ]]);
    expect($product->variations()->pluck('sku')->all())->toBe(['TSH-01', 'TSH-02', 'TSH-03']);

    $state = SaveProduct::formState($product);
    $state['variations'][0]['sell_price'] = 95;
    unset($state['variations'][2]); // drop L

    app(SaveProduct::class)->handle($state, $product);

    expect($product->variations()->pluck('name')->all())->toBe(['S', 'M'])
        ->and((float) $product->variations()->where('name', 'S')->value('sell_price_inc_tax'))->toBe(95.0)
        ->and($product->variations()->withTrashed()->count())->toBe(3);
});

it('frees the barcodes of a removed variation', function () {
    $product = app(SaveProduct::class)->handle([...$this->base, 'type' => 'variable', 'variations' => [
        ['name' => 'S', 'sell_price' => 1], ['name' => 'M', 'sell_price' => 1, 'barcodes' => ['M-CODE']],
    ]]);
    $state = SaveProduct::formState($product);
    unset($state['variations'][1]);
    app(SaveProduct::class)->handle($state, $product);

    $other = app(SaveProduct::class)->handle([...$this->base, 'name' => 'آخر', 'sell_price' => 1, 'barcodes' => ['M-CODE']]);
    expect($other->defaultVariation->barcodes->sole()->barcode)->toBe('M-CODE');
});

it('stores price group prices and round-trips form state', function () {
    $group = PriceGroup::query()->firstOrFail();
    $product = app(SaveProduct::class)->handle([...$this->base, 'purchase_price' => 5, 'sell_price' => 10,
        'group_prices' => [['price_group_id' => $group->id, 'value' => 8]], 'location_ids' => []]);

    $state = SaveProduct::formState($product);
    expect($state['group_prices'])->toBe([['price_group_id' => $group->id, 'price_type' => 'fixed', 'value' => 8.0]])
        ->and($state['sell_price'])->toBe(10.0)
        ->and($state['purchase_price'])->toBe(5.0);
});

it('creates a combo that does not track its own stock', function () {
    $component = makeVariation();
    $combo = app(SaveProduct::class)->handle([...$this->base, 'type' => 'combo', 'name' => 'وجبة', 'sell_price' => 50,
        'combo_items' => [['component_variation_id' => $component->id, 'qty' => 2]]]);

    expect($combo->type)->toBe(ProductType::Combo)
        ->and($combo->tracksStock())->toBeFalse()
        ->and($combo->defaultVariation->comboItems->sole()->qty)->toBe('2.0000');

    expect(fn () => app(SaveProduct::class)->handle([...$this->base, 'type' => 'combo', 'name' => 'x', 'sell_price' => 1,
        'combo_items' => [['component_variation_id' => $combo->defaultVariation->id, 'qty' => 1]]]))->toThrow(DomainException::class);
});
