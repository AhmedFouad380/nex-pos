<?php

namespace App\Filament\Admin\Pages\Settings;

use App\Domain\Foundation\Models\Currency;
use App\Domain\Inventory\Enums\CostingMethod;
use App\Filament\NavigationGroup;
use App\Filament\Support\Branding;
use App\Settings\GeneralSettings;
use App\Settings\InventorySettings;
use App\Settings\PosSettings;
use App\Settings\PurchaseSettings;
use App\Settings\WeighingScaleSettings;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Spatie\LaravelSettings\Settings;

/**
 * @property-read Schema $form
 *
 * إعدادات النشاط: one form over several settings classes. Only settings the app actually honours
 * are shown — the rest stay in their classes until the feature that reads them is built.
 */
class BusinessSettings extends Page
{
    protected static string|\UnitEnum|null $navigationGroup = NavigationGroup::Settings;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static ?int $navigationSort = 1;

    protected static ?string $slug = 'settings/business';

    /** form key => [settings class, editable properties] */
    private const GROUPS = [
        'general' => [GeneralSettings::class, ['business_name', 'logo_path', 'theme_color', 'address', 'phone', 'email', 'currency_code', 'currency_precision',
            'date_format', 'timezone', 'tax_label_1', 'tax_number_1', 'tax_label_2', 'tax_number_2']],
        'pos' => [PosSettings::class, ['require_open_register', 'enable_draft', 'enable_suspend', 'enable_quotation', 'enable_card_payment',
            'enable_multiple_payments', 'enable_credit_sale', 'allow_discount', 'allow_price_edit', 'print_receipt_on_finalize', 'keyboard_shortcuts']],
        'inventory' => [InventorySettings::class, ['costing_method', 'allow_overselling', 'sku_prefix', 'enable_brands', 'enable_categories',
            'enable_sub_units', 'enable_lots', 'enable_expiry', 'enable_serials', 'stop_selling_days_before_expiry']],
        'purchase' => [PurchaseSettings::class, ['enable_purchase_status', 'enable_editing_product_from_purchase', 'enable_foreign_currency', 'default_exchange_rate']],
        'scale' => [WeighingScaleSettings::class, ['enabled', 'label_prefix', 'sku_length', 'qty_integer_length', 'qty_decimal_length']],
    ];

    private const SHORTCUTS = ['express_checkout', 'pay_card', 'multiple_pay', 'draft', 'suspend', 'cancel', 'focus_search', 'add_customer'];

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->can('settings.manage');
    }

    public static function getNavigationLabel(): string
    {
        return __('Business settings');
    }

    public function getTitle(): string
    {
        return __('Business settings');
    }

    public function mount(): void
    {
        $data = [];
        foreach (self::GROUPS as $key => [$class, $properties]) {
            /** @var Settings $settings */
            $settings = app($class);
            $values = $settings->toArray();
            foreach ($properties as $property) {
                $data[$key][$property] = $values[$property] ?? null;
            }
        }
        $data['pos']['keyboard_shortcuts'] = array_merge(array_fill_keys(self::SHORTCUTS, ''), $data['pos']['keyboard_shortcuts'] ?? []);
        $this->form->fill($data);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            Tabs::make()->persistTabInQueryString()->tabs([
                Tab::make(__('Business'))->icon(Heroicon::OutlinedBuildingStorefront)->schema([
                    Section::make()->columns(2)->schema([
                        TextInput::make('general.business_name')->label(__('Business name'))->required()->maxLength(255),
                        FileUpload::make('general.logo_path')->label(__('Logo'))->image()->disk('public')->directory('branding')
                            ->maxSize(1024)->helperText(__('Printed on invoices, receipts and vouchers.')),
                        Select::make('general.theme_color')->label(__('Main colour'))->required()->allowHtml()->native(false)
                            ->options(fn () => collect(Branding::PALETTES)->mapWithKeys(fn (array $palette, string $key) => [$key => sprintf(
                                '<span style="display:inline-flex;align-items:center;gap:.5rem"><span style="width:1rem;height:1rem;border-radius:999px;background:%s;display:inline-block"></span>%s</span>',
                                $palette[500], e(__("colours.{$key}")),
                            )])->all())
                            ->helperText(__('Colours the whole program. Takes effect after saving.')),
                        TextInput::make('general.address')->label(__('Address'))->maxLength(255),
                        TextInput::make('general.phone')->label(__('Phone'))->maxLength(30),
                        TextInput::make('general.email')->label(__('Email'))->email(),
                    ]),
                    Section::make(__('Tax and registration numbers'))->columns(2)->schema([
                        TextInput::make('general.tax_label_1')->label(__('Label').' 1')->maxLength(60),
                        TextInput::make('general.tax_number_1')->label(__('Number').' 1')->maxLength(60),
                        TextInput::make('general.tax_label_2')->label(__('Label').' 2')->maxLength(60),
                        TextInput::make('general.tax_number_2')->label(__('Number').' 2')->maxLength(60),
                    ]),
                    Section::make(__('Currency and dates'))->columns(2)->schema([
                        Select::make('general.currency_code')->label(__('Currency'))->required()
                            ->options(fn () => Currency::query()->orderBy('code')->get()->mapWithKeys(fn ($c) => [$c->code => "{$c->code} — {$c->name}"])),
                        Select::make('general.currency_precision')->label(__('Decimal places'))->options([0 => '0', 1 => '1', 2 => '2', 3 => '3'])->required(),
                        Select::make('general.date_format')->label(__('Date format'))->required()->options([
                            'd/m/Y' => now()->format('d/m/Y'), 'd-m-Y' => now()->format('d-m-Y'), 'Y-m-d' => now()->format('Y-m-d'), 'm/d/Y' => now()->format('m/d/Y'),
                        ]),
                        Select::make('general.timezone')->label(__('Time zone'))->required()->searchable()
                            ->options(fn () => array_combine(timezone_identifiers_list(), timezone_identifiers_list())),
                    ]),
                ]),
                Tab::make(__('Point of sale'))->icon(Heroicon::OutlinedShoppingBag)->schema([
                    Section::make(__('Buttons and permissions on the cashier screen'))->columns(2)->schema([
                        Toggle::make('pos.require_open_register')->label(__('Open a shift before selling')),
                        Toggle::make('pos.print_receipt_on_finalize')->label(__('Print the receipt automatically')),
                        Toggle::make('pos.enable_card_payment')->label(__('Card payment')),
                        Toggle::make('pos.enable_multiple_payments')->label(__('Split payment')),
                        Toggle::make('pos.enable_credit_sale')->label(__('Credit sales'))
                            ->helperText(__('Also needs the permission ":permission" in the user role.', ['permission' => __('permissions.permissions')['pos.credit_sale'] ?? 'pos.credit_sale'])),
                        Toggle::make('pos.enable_suspend')->label(__('Suspend sales')),
                        Toggle::make('pos.enable_draft')->label(__('Drafts')),
                        Toggle::make('pos.enable_quotation')->label(__('Quotations')),
                        Toggle::make('pos.allow_discount')->label(__('Discounts'))
                            ->helperText(__('Also needs the permission ":permission" in the user role.', ['permission' => __('permissions.permissions')['pos.edit_discount'] ?? 'pos.edit_discount'])),
                        Toggle::make('pos.allow_price_edit')->label(__('Changing the price'))
                            ->helperText(__('Also needs the permission ":permission" in the user role.', ['permission' => __('permissions.permissions')['pos.edit_price'] ?? 'pos.edit_price'])),
                    ]),
                    Section::make(__('Keyboard shortcuts'))->description(__('Like shift+e or ctrl+k.'))->columns(4)->schema(
                        array_map(fn (string $action) => TextInput::make("pos.keyboard_shortcuts.{$action}")->label(__("shortcut.{$action}"))->maxLength(20),
                            self::SHORTCUTS),
                    ),
                ]),
                Tab::make(__('Stock settings'))->icon(Heroicon::OutlinedArchiveBox)->schema([
                    Section::make()->columns(2)->schema([
                        Select::make('inventory.costing_method')->label(__('Costing method'))->options(CostingMethod::class)->required()
                            ->helperText(__('Applies to stock taken out from now on.')),
                        TextInput::make('inventory.sku_prefix')->label(__('SKU prefix'))->maxLength(10),
                        Toggle::make('inventory.allow_overselling')->label(__('Allow selling below zero stock')),
                        Toggle::make('inventory.enable_brands')->label(__('Brands')),
                        Toggle::make('inventory.enable_categories')->label(__('Categories')),
                        Toggle::make('inventory.enable_sub_units')->label(__('Sub-units (box, carton)')),
                        Toggle::make('inventory.enable_lots')->label(__('Lot numbers')),
                        Toggle::make('inventory.enable_expiry')->label(__('Expiry dates'))->live(),
                        Toggle::make('inventory.enable_serials')->label(__('Serial numbers')),
                        TextInput::make('inventory.stop_selling_days_before_expiry')->label(__('Stop selling this many days before expiry'))
                            ->numeric()->minValue(0)->visible(fn (Get $get) => (bool) $get('inventory.enable_expiry')),
                    ]),
                ]),
                Tab::make(__('Purchases'))->icon(Heroicon::OutlinedShoppingCart)->schema([
                    Section::make()->columns(2)->schema([
                        Toggle::make('purchase.enable_purchase_status')->label(__('Receive goods later (pending purchases)')),
                        Toggle::make('purchase.enable_editing_product_from_purchase')->label(__('Update selling prices from the purchase screen')),
                        Toggle::make('purchase.enable_foreign_currency')->label(__('Purchases in foreign currency'))->live(),
                        TextInput::make('purchase.default_exchange_rate')->label(__('Default exchange rate'))->numeric()->minValue(0)
                            ->visible(fn (Get $get) => (bool) $get('purchase.enable_foreign_currency')),
                    ]),
                ]),
                Tab::make(__('Weighing scale'))->icon(Heroicon::OutlinedScale)->schema([
                    Section::make()->description(__('Barcodes printed by the scale: prefix + item code + weight.'))->columns(2)->schema([
                        Toggle::make('scale.enabled')->label(__('Read scale barcodes'))->columnSpanFull(),
                        TextInput::make('scale.label_prefix')->label(__('Prefix'))->required()->maxLength(4),
                        TextInput::make('scale.sku_length')->label(__('Item code digits'))->numeric()->integer()->minValue(1)->maxValue(10)->required(),
                        TextInput::make('scale.qty_integer_length')->label(__('Weight digits before the point'))->numeric()->integer()->minValue(1)->maxValue(6)->required(),
                        TextInput::make('scale.qty_decimal_length')->label(__('Weight digits after the point'))->numeric()->integer()->minValue(0)->maxValue(4)->required(),
                    ]),
                ]),
            ]),
        ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('save')
                ->footer([Actions::make([Action::make('save')->label(__('Save'))->submit('save')])]),
        ]);
    }

    public function save(): void
    {
        abort_unless(static::canAccess(), 403);
        $data = $this->form->getState();
        $data['pos']['keyboard_shortcuts'] = array_filter(array_map(fn ($v) => strtolower(trim((string) $v)), $data['pos']['keyboard_shortcuts'] ?? []));

        foreach (self::GROUPS as $key => [$class, $properties]) {
            /** @var Settings $settings */
            $settings = app($class);
            $values = [];
            foreach ($properties as $property) {
                if (array_key_exists($property, $data[$key] ?? [])) {
                    $values[$property] = $this->cast($settings, $property, $data[$key][$property]);
                }
            }
            $settings->fill($values)->save();
        }

        Notification::make()->success()->title(__('Saved'))->send();
        $this->redirect(static::getUrl(), navigate: false); // reload so a new colour / logo shows at once
    }

    /** Form values come back as strings / enums; settings properties are typed. */
    private function cast(Settings $settings, string $property, mixed $value): mixed
    {
        $value = $value instanceof \BackedEnum ? $value->value : $value;
        $type = (new \ReflectionProperty($settings, $property))->getType();
        $name = $type instanceof \ReflectionNamedType ? $type->getName() : 'mixed';
        if ($value === '' || $value === null) {
            return $type?->allowsNull() ? null : match ($name) {
                'int' => 0, 'float' => 0.0, 'bool' => false, 'array' => [], default => '',
            };
        }

        return match ($name) {
            'int' => (int) $value,
            'float' => (float) $value,
            'bool' => (bool) $value,
            'string' => (string) $value,
            default => $value,
        };
    }
}
