<?php

use App\Domain\Catalog\Models\TaxRate;
use App\Domain\Catalog\Models\Unit;
use App\Domain\Foundation\Models\Location;
use App\Filament\Admin\Resources\Catalog\Pages;
use App\Filament\Admin\Resources\Settings\Pages\ManageLocations;
use App\Filament\Admin\Resources\Settings\Pages\ManageUsers;
use App\Models\User;
use Filament\Facades\Filament;
use Spatie\Permission\Models\Role;

use function Pest\Livewire\livewire;

beforeEach(function () {
    seedBaseData();
    $this->actingAs(User::query()->firstOrFail());
    Filament::setCurrentPanel('admin');
});

it('renders every settings-style page', function (string $page) {
    livewire($page)->assertOk();
})->with([
    Pages\ManageUnits::class, Pages\ManageBrands::class, Pages\ManageCategories::class, Pages\ManageTaxRates::class,
    Pages\ManageWarranties::class, Pages\ManagePriceGroups::class, Pages\ManageVariationTemplates::class,
    ManageLocations::class, ManageUsers::class,
]);

it('creates a sub-unit', function () {
    $piece = Unit::query()->where('name', 'قطعة')->firstOrFail();

    livewire(Pages\ManageUnits::class)
        ->callAction('create', ['name' => 'شوال', 'short_name' => 'شوال', 'base_unit_id' => $piece->id, 'base_multiplier' => 50])
        ->assertHasNoActionErrors();

    expect(Unit::query()->where('name', 'شوال')->value('base_multiplier'))->toBe('50.0000');
});

it('keeps a tax group rate equal to the sum of its members', function () {
    $a = TaxRate::query()->create(['name' => 'A', 'rate' => 10]);
    $b = TaxRate::query()->create(['name' => 'B', 'rate' => 4]);

    livewire(Pages\ManageTaxRates::class)
        ->callAction('create', ['name' => 'A+B', 'is_group' => true, 'members' => [$a->id, $b->id], 'is_active' => true])
        ->assertHasNoActionErrors();

    expect(TaxRate::query()->where('name', 'A+B')->value('rate'))->toBe('14.0000');
});

it('gives every new location a cash drawer', function () {
    livewire(ManageLocations::class)->callAction('create', ['name' => 'فرع الإسكندرية', 'code' => 'ALX', 'is_active' => true]);

    expect(Location::query()->where('code', 'ALX')->firstOrFail()->cashRegisters()->count())->toBe(1);
});

it('stops a manager from creating a super admin even with a crafted request', function () {
    $manager = User::factory()->create();
    $manager->assignRole('manager');
    $this->actingAs($manager);

    livewire(ManageUsers::class)
        ->callAction('create', ['name' => 'x', 'email' => 'x@x.test', 'password' => 'password123',
            'roles' => [Role::findByName('super-admin')->id]])
        ->assertHasActionErrors(['roles']);

    expect(User::query()->where('email', 'x@x.test')->exists())->toBeFalse();
});
