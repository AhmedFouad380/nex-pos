<?php

use App\Domain\Catalog\Enums\ProductType;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Foundation\Models\Location;

beforeEach(fn () => seedBaseData());

it('adds the maintenance services under the maintenance category, sold in every branch, once', function () {
    Category::query()->create(['name' => 'صيانة ']); // the old system's category, with its trailing space

    $this->artisan('catalog:maintenance-services')->assertSuccessful();

    $parent = Category::query()->whereNull('parent_id')->whereRaw('trim(name) = ?', ['صيانة'])->sole();
    $screen = Category::query()->where('parent_id', $parent->id)->where('name', 'الشاشة واللمس')->sole();
    $service = Product::query()->where('name', 'تغيير بطارية')->sole();
    expect(Category::query()->where('parent_id', $parent->id)->count())->toBe(12)
        ->and(Product::query()->where('type', ProductType::Service)->count())->toBe(89)
        ->and($screen->products()->count())->toBe(8)
        ->and($service->tracksStock())->toBeFalse()
        ->and($service->locations()->count())->toBe(Location::query()->where('is_active', true)->count());

    $this->artisan('catalog:maintenance-services')->assertSuccessful();
    expect(Product::query()->where('type', ProductType::Service)->count())->toBe(89);
});
