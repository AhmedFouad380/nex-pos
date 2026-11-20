<?php

use App\Domain\Catalog\Models\Variation;
use App\Domain\Finance\Enums\AccountSubType;
use App\Domain\Finance\Models\Account;
use App\Domain\Finance\Models\PaymentMethod;
use App\Domain\Foundation\Models\DocumentSequence;
use App\Domain\Foundation\Models\Location;
use App\Domain\Foundation\Services\DocumentNumberGenerator;
use App\Domain\Printing\Enums\PaperSize;
use App\Domain\Printing\Models\InvoiceLayout;
use App\Domain\Printing\Models\LabelTemplate;
use App\Domain\Printing\Services\Code128;
use App\Domain\Printing\Services\LabelSheet;
use App\Domain\Sales\Actions\CreateSaleReturn;
use App\Domain\Shared\Enums\DocumentType;
use App\Filament\Admin\Pages\PrintLabels;
use App\Filament\Admin\Pages\Settings\BusinessSettings;
use App\Filament\Admin\Resources\Printing\Pages\ManageInvoiceLayouts;
use App\Filament\Admin\Resources\Printing\Pages\ManageLabelTemplates;
use App\Filament\Admin\Resources\Settings\Pages\ManageDocumentSequences;
use App\Filament\Admin\Resources\Settings\Pages\ManagePaymentMethods;
use App\Filament\Admin\Resources\Settings\Pages\ManageRoles;
use App\Filament\Admin\Resources\Settings\RoleResource;
use App\Filament\Support\Branding;
use App\Models\User;
use App\Settings\GeneralSettings;
use App\Settings\InventorySettings;
use App\Settings\PosSettings;
use Filament\Facades\Filament;
use Filament\Support\Colors\Color;
use Spatie\Permission\Models\Role;

use function Pest\Livewire\livewire;

beforeEach(function () {
    seedBaseData();
    $this->actingAs(User::query()->firstOrFail());
    Filament::setCurrentPanel('admin');
    $this->location = Location::query()->firstOrFail();
});

it('saves the business settings across their groups', function () {
    livewire(BusinessSettings::class)->assertOk()
        ->fillForm([
            'general.business_name' => 'سوبر ماركت النور', 'general.currency_precision' => 3, 'general.tax_number_1' => '123-456-789',
            'pos.enable_credit_sale' => false, 'pos.keyboard_shortcuts.express_checkout' => 'F9',
            'inventory.costing_method' => 'avg', 'inventory.allow_overselling' => true, 'scale.sku_length' => 6,
        ])
        ->call('save')->assertHasNoFormErrors()->assertNotified(__('Saved'));

    $general = app(GeneralSettings::class)->refresh();
    expect($general->business_name)->toBe('سوبر ماركت النور')->and($general->currency_precision)->toBe(3)
        ->and($general->tax_number_1)->toBe('123-456-789')
        ->and(app(PosSettings::class)->refresh()->enable_credit_sale)->toBeFalse()
        ->and(app(PosSettings::class)->keyboard_shortcuts['express_checkout'])->toBe('f9')
        ->and(app(InventorySettings::class)->refresh()->costing_method)->toBe('avg')
        ->and(app(InventorySettings::class)->allow_overselling)->toBeTrue();
});

it('numbers documents per branch when a branch sequence exists', function () {
    livewire(ManageDocumentSequences::class)->assertOk()
        ->callAction('create', ['document_type' => 'sale', 'location_id' => $this->location->id, 'prefix' => 'CAI-', 'next_number' => 100, 'padding' => 4])
        ->assertHasNoActionErrors();

    expect(app(DocumentNumberGenerator::class)->next(DocumentType::Sale, $this->location->id))->toBe('CAI-0100')
        ->and(DocumentSequence::query()->where('document_type', 'sale')->count())->toBe(2);
});

it('adds a payment method paid into a bank', function () {
    $bank = Account::query()->where('sub_type', AccountSubType::Bank)->value('id');
    livewire(ManagePaymentMethods::class)->assertOk()
        ->callAction('create', ['name' => 'فوري', 'type' => 'wallet', 'account_id' => $bank, 'sort' => 9, 'is_active' => true])
        ->assertHasNoActionErrors();

    expect(PaymentMethod::query()->where('name', 'فوري')->value('account_id'))->toBe($bank);
});

it('creates a custom role with chosen permissions and protects the built-in ones', function () {
    livewire(ManageRoles::class)->assertOk()
        ->callAction('create', ['name' => 'مشرف صالة', 'permissions' => ['access' => ['admin.access'], 'sales' => ['sales.view']]])
        ->assertHasNoActionErrors();

    $role = Role::findByName('مشرف صالة');
    expect($role->permissions->pluck('name')->sort()->values()->all())->toBe(['admin.access', 'sales.view']);

    $user = User::factory()->create();
    $user->assignRole($role);
    expect($user->can('sales.view'))->toBeTrue()->and($user->can('sales.void'))->toBeFalse();

    livewire(ManageRoles::class)->assertTableActionHidden('edit', Role::findByName('super-admin'))
        ->assertTableActionHidden('delete', Role::findByName('cashier'));
});

it('prints an A4 invoice and a receipt following the layout', function () {
    $tea = makeVariation([], ['purchase_price' => 6, 'sell_price' => 10, 'sell_price_inc_tax' => 10]);
    makePurchase(makeSupplier(), [[$tea, 10, 6]]);
    $sale = sell([[$tea, 3]], [cash(30)], ['cashSessionId' => openSession()->id]);

    livewire(ManageInvoiceLayouts::class)->assertOk()
        ->callAction('create', ['name' => 'فاتورة مخصصة', 'paper' => PaperSize::A5, 'is_default' => true,
            'settings' => ['show_sku' => true, 'show_amount_in_words' => true, 'header_text' => 'فرع وسط البلد', 'labels' => ['invoice_heading' => 'فاتورة ضريبية']]])
        ->assertHasNoActionErrors();
    $layout = InvoiceLayout::query()->where('name', 'فاتورة مخصصة')->firstOrFail();
    expect(InvoiceLayout::query()->where('is_default', true)->whereIn('paper', ['a4', 'a5'])->count())->toBe(1);
    $this->location->update(['invoice_layout_id' => $layout->id]); // the branch prints with it

    $this->get(route('print.sale', $sale))->assertOk()
        ->assertSee('فاتورة ضريبية')->assertSee('فرع وسط البلد')->assertSee($tea->sku)->assertSee('فقط ثلاثون جنيه مصري لا غير');
    $this->get(route('pos.receipt', $sale))->assertOk()->assertSee($sale->invoice_no)->assertSee('80mm', false);

    $return = app(CreateSaleReturn::class)->handle($sale, [['sale_item_id' => $sale->items()->value('id'), 'qty' => 1]]);
    $this->get(route('print.sale_return', $return))->assertOk()->assertSee($return->number)->assertSee($sale->invoice_no);

    expect($layout->paper)->toBe(PaperSize::A5);
});

it('encodes Code 128 with the right checksum', function () {
    expect(Code128::encode('PJJ123C'))->toBe([104, 48, 42, 42, 17, 18, 19, 35, 55, 106])
        ->and(Code128::encode('123456'))->toBe([105, 12, 34, 56, 44, 106]) // set C: (105 + 12×1 + 34×2 + 56×3) % 103 = 44
        ->and(Code128::svg('ABC-1'))->toContain('<svg')
        ->and(Code128::svg('عربي'))->toBe('');
});

it('prints barcode labels from a purchase and from selected products', function () {
    $tea = makeVariation(['name' => 'شاي العروسة'], ['sell_price' => 12, 'sell_price_inc_tax' => 12]);
    $tea->barcodes()->create(['barcode' => '6221234567890']);
    $purchase = makePurchase(makeSupplier(), [[$tea, 3, 6]]);

    expect(LabelSheet::labels([$tea->id => 3]))->toHaveCount(3)
        ->and(LabelSheet::labels([$tea->id => 3])[0]['barcode'])->toBe('6221234567890');

    $page = livewire(PrintLabels::class, [])->assertOk();
    $this->get(PrintLabels::getUrl(['purchase' => $purchase->id]))->assertOk()->assertSee('شاي العروسة');

    $template = LabelTemplate::query()->where('is_continuous', false)->firstOrFail();
    $html = $this->get(route('print.labels', ['template' => $template->id, 'items' => [$tea->id => 2], 'show' => ['name', 'price', 'barcode']]))
        ->assertOk()->assertSee('شاي العروسة')->assertSee('6221234567890')->assertSee('12.00')->getContent();
    expect(substr_count($html, 'class="label"'))->toBe(2)->and($html)->toContain('repeat(4, 48.5mm)');

    livewire(ManageLabelTemplates::class)->assertOk()
        ->callAction('create', ['name' => 'رول 50×30', 'is_continuous' => true, 'is_default' => true, 'paper' => ['label_width' => 50, 'label_height' => 30]])
        ->assertHasNoActionErrors();
    expect(LabelTemplate::query()->where('is_default', true)->value('name'))->toBe('رول 50×30');
    expect($page)->not->toBeNull();
});

it('keeps settings and printing setup to the right people', function () {
    $cashier = User::factory()->create();
    $cashier->assignRole('storekeeper');
    $this->flushSession();
    $this->actingAs($cashier);

    $this->get(BusinessSettings::getUrl())->assertForbidden();
    $this->get(RoleResource::getUrl())->assertForbidden();
    $this->get(PrintLabels::getUrl())->assertOk(); // storekeepers print shelf labels
    expect(Variation::query()->count())->toBe(0);
});

it('colours both panels with the main colour chosen in the settings', function () {
    expect(Branding::primaryKey())->toBe('amber');

    livewire(BusinessSettings::class)->fillForm(['general.theme_color' => 'violet'])->call('save')->assertHasNoFormErrors();

    expect(Branding::primaryKey())->toBe('violet')
        ->and(Branding::colors()['primary'])->toBe(Color::Violet)
        ->and(Branding::colors()['warning'])->toBe(Color::Amber);
    $this->get('/admin')->assertOk();
});

it('names every permission in the role form instead of showing its key', function () {
    app()->setLocale('ar');
    foreach (config('permissions.groups') as $permissions) {
        foreach ($permissions as $permission) {
            expect(RoleResource::permissionLabel($permission))->not->toBe($permission, "{$permission} has no Arabic label");
        }
    }
    expect(RoleResource::permissionLabel('users.view'))->toBe('عرض المستخدمين');
});
