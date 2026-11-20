<?php

namespace App\Filament\Admin\Pages;

use App\Domain\Catalog\Models\Product;
use App\Domain\CRM\Enums\ContactType;
use App\Domain\CRM\Models\Contact;
use App\Domain\Foundation\Models\Location;
use App\Domain\Inventory\Models\StockLevel;
use App\Domain\Payroll\Models\EmployeeProfile;
use App\Domain\Wallet\Models\Wallet;
use App\Filament\Admin\Pages\Settings\BusinessSettings;
use App\Filament\Admin\Resources\Customers\CustomerResource;
use App\Filament\Admin\Resources\Employees\EmployeeResource;
use App\Filament\Admin\Resources\Products\ProductResource;
use App\Filament\Admin\Resources\Settings\LocationResource;
use App\Filament\Admin\Resources\Settings\UserResource;
use App\Filament\Admin\Resources\StockAdjustments\StockAdjustmentResource;
use App\Filament\Admin\Resources\Suppliers\SupplierResource;
use App\Filament\Admin\Resources\Wallets\Wallets\WalletResource;
use App\Filament\NavigationGroup;
use App\Models\User;
use App\Settings\GeneralSettings;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/**
 * ابدأ من هنا: the setup steps of a new installation, each ticked automatically from the data,
 * with a link to the screen where it is done.
 */
class GettingStarted extends Page
{
    protected static string|\UnitEnum|null $navigationGroup = NavigationGroup::Settings;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedRocketLaunch;

    protected static ?int $navigationSort = -10;

    protected static ?string $slug = 'getting-started';

    protected string $view = 'filament.admin.getting-started';

    public static function getNavigationLabel(): string
    {
        return __('Getting started');
    }

    public function getTitle(): string
    {
        return __('Getting started');
    }

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->can('settings.manage');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('guide')->label(__('Download the user guide (PDF)'))->icon('heroicon-o-book-open')->color('gray')
                ->url(asset('guide/user-guide.pdf'), shouldOpenInNewTab: true)
                ->visible(fn () => file_exists(public_path('guide/user-guide.pdf'))),
        ];
    }

    public static function getNavigationBadge(): ?string
    {
        $left = collect(self::steps())->where('required', true)->where('done', false)->count();

        return $left > 0 ? (string) $left : null;
    }

    public static function getNavigationBadgeColor(): string
    {
        return 'warning';
    }

    /**
     * @return list<array{key: string, title: string, description: string, done: bool, required: bool, url: ?string, action: string}>
     */
    public static function steps(): array
    {
        $settings = rescue(fn () => app(GeneralSettings::class), null, false);
        $customers = Contact::query()->whereIn('type', [ContactType::Customer, ContactType::Both])->where('is_default', false);
        $suppliers = Contact::query()->whereIn('type', [ContactType::Supplier, ContactType::Both]);
        $url = fn (string $class, string $page = 'index') => rescue(fn () => $class::getUrl($page), null, false);

        return [
            self::step('business', true, $settings !== null && $settings->business_name !== 'My Business' && filled($settings->phone ?? $settings->address),
                rescue(fn () => BusinessSettings::getUrl(), null, false)),
            self::step('locations', true, Location::query()->whereNotNull('address')->exists() || Location::query()->count() > 1,
                $url(LocationResource::class)),
            self::step('users', true, User::query()->count() > 1, $url(UserResource::class)),
            self::step('products', true, Product::query()->exists(), $url(ProductResource::class)),
            self::step('opening_stock', true, StockLevel::query()->where('qty_on_hand', '>', 0)->exists(), $url(StockAdjustmentResource::class, 'create')),
            self::step('suppliers', false, $suppliers->exists(), $url(SupplierResource::class)),
            self::step('customers', false, $customers->exists(), $url(CustomerResource::class)),
            self::step('employees', false, EmployeeProfile::query()->exists(), $url(EmployeeResource::class)),
            self::step('wallets', false, Wallet::query()->exists(), $url(WalletResource::class)),
        ];
    }

    /** @return array{key: string, title: string, description: string, done: bool, required: bool, url: ?string, action: string} */
    private static function step(string $key, bool $required, bool $done, ?string $url): array
    {
        return [
            'key' => $key,
            'title' => __("onboarding.steps.{$key}.title"),
            'description' => __("onboarding.steps.{$key}.description"),
            'action' => __("onboarding.steps.{$key}.action"),
            'done' => $done,
            'required' => $required,
            'url' => $url,
        ];
    }
}
