<?php

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Unit;
use App\Filament\Admin\Resources\Products\Pages\CreateProduct;
use App\Filament\Admin\Resources\Products\Pages\EditProduct;
use App\Filament\Admin\Resources\Products\Pages\ListProducts;
use App\Filament\Admin\Resources\Products\Pages\ViewProduct;
use App\Filament\Admin\Resources\Products\ProductResource;
use App\Models\User;
use Filament\Facades\Filament;

use function Pest\Livewire\livewire;

beforeEach(function () {
    seedBaseData();
    $this->actingAs(User::query()->firstOrFail());
    Filament::setCurrentPanel('admin');
    $this->piece = Unit::query()->where('name', 'قطعة')->firstOrFail();
});

it('renders the product list, create, edit and view pages', function () {
    $product = makeVariation()->product;

    livewire(ListProducts::class)->assertOk()->assertCanSeeTableRecords([$product]);
    livewire(CreateProduct::class)->assertOk();
    livewire(EditProduct::class, ['record' => $product->id])->assertOk();
    livewire(ViewProduct::class, ['record' => $product->id])->assertOk();
});

it('creates a product from the form', function () {
    livewire(CreateProduct::class)
        ->fillForm(['name' => 'عصير مانجو', 'type' => 'single', 'unit_id' => $this->piece->id, 'purchase_price' => 10, 'sell_price' => 15, 'barcodes' => ['6220000000001']])
        ->call('create')
        ->assertHasNoFormErrors();

    $product = Product::query()->where('name', 'عصير مانجو')->firstOrFail();
    expect((float) $product->defaultVariation->sell_price_inc_tax)->toBe(15.0)
        ->and($product->locations()->count())->toBe(1); // defaults to all active locations
});

it('shows a duplicate barcode as a notification instead of crashing', function () {
    $existing = makeVariation();
    $existing->barcodes()->create(['barcode' => 'DUP']);

    livewire(CreateProduct::class)
        ->fillForm(['name' => 'منتج', 'type' => 'single', 'unit_id' => $this->piece->id, 'sell_price' => 1, 'barcodes' => ['DUP']])
        ->call('create')
        ->assertNotified();

    expect(Product::query()->where('name', 'منتج')->exists())->toBeFalse();
});

it('edits a product keeping its prices', function () {
    livewire(CreateProduct::class)
        ->fillForm(['name' => 'أرز', 'type' => 'single', 'unit_id' => $this->piece->id, 'purchase_price' => 20, 'sell_price' => 30])
        ->call('create');
    $product = Product::query()->where('name', 'أرز')->firstOrFail();

    livewire(EditProduct::class, ['record' => $product->id])
        ->assertSchemaStateSet(['sell_price' => 30.0, 'purchase_price' => 20.0])
        ->fillForm(['sell_price' => 32])
        ->call('save')
        ->assertHasNoFormErrors();

    expect((float) $product->defaultVariation()->value('sell_price_inc_tax'))->toBe(32.0);
});

it('hides products from users without permission', function () {
    $cashier = User::factory()->create();
    $cashier->assignRole('cashier');
    $this->actingAs($cashier);

    $this->get(ProductResource::getUrl())->assertForbidden();
});
