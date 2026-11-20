<?php

namespace Database\Seeders;

use App\Domain\CashManagement\Actions\CloseCashSession;
use App\Domain\CashManagement\Actions\OpenCashSession;
use App\Domain\CashManagement\Models\CashRegister;
use App\Domain\CashManagement\Services\CashSessionSummary;
use App\Domain\Catalog\Enums\ProductType;
use App\Domain\Catalog\Enums\TaxType;
use App\Domain\Catalog\Models\Brand;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\PriceGroup;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\TaxRate;
use App\Domain\Catalog\Models\Unit;
use App\Domain\Catalog\Models\Variation;
use App\Domain\Catalog\Models\VariationTemplate;
use App\Domain\CRM\Actions\RecordOpeningBalance;
use App\Domain\CRM\Enums\BalanceDirection;
use App\Domain\CRM\Enums\ContactType;
use App\Domain\CRM\Models\Contact;
use App\Domain\CRM\Models\CustomerGroup;
use App\Domain\Finance\Actions\CreateExpense;
use App\Domain\Finance\Actions\RecordJournalEntry;
use App\Domain\Finance\Actions\RecordPayment;
use App\Domain\Finance\Actions\TransferBetweenAccounts;
use App\Domain\Finance\Data\PaymentData;
use App\Domain\Finance\Enums\AccountSubType;
use App\Domain\Finance\Enums\PaymentDirection;
use App\Domain\Finance\Models\Account;
use App\Domain\Finance\Models\Expense;
use App\Domain\Finance\Models\ExpenseCategory;
use App\Domain\Finance\Models\PaymentMethod;
use App\Domain\Foundation\Models\Location;
use App\Domain\Foundation\Services\DocumentNumberGenerator;
use App\Domain\Inventory\Actions\CreateStockAdjustment;
use App\Domain\Inventory\Data\StockLineData;
use App\Domain\Inventory\Enums\StockAdjustmentType;
use App\Domain\Inventory\Models\StockAdjustment;
use App\Domain\Purchasing\Actions\CreatePurchase;
use App\Domain\Purchasing\Data\PurchaseData;
use App\Domain\Purchasing\Data\PurchaseLineData;
use App\Domain\Purchasing\Models\Purchase;
use App\Domain\Sales\Actions\CreateSaleReturn;
use App\Domain\Sales\Actions\SaveSale;
use App\Domain\Sales\Data\SaleData;
use App\Domain\Sales\Data\SaleLineData;
use App\Domain\Sales\Models\Sale;
use App\Domain\Shared\Enums\DocumentType;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Demo catalog + contacts + staff for local development and client demos.
 * Never run on a client's production install. Stock goes through the real stock ledger
 * as an opening-stock adjustment, exactly like a user would enter it.
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command?->error('DemoSeeder refuses to run in production.');

            return;
        }

        $this->call(DatabaseSeeder::class);

        DB::transaction(function (): void {
            $this->staff();
            $this->contacts();
            $this->catalog();
            $this->openingStock();
            $this->purchasing();
            $this->sales();
            $this->finance();
        });
    }

    private function staff(): void
    {
        $location = Location::query()->firstOrFail();

        foreach ([
            ['cashier@newpos.test', 'كاشير تجريبي', 'cashier', 'cashier'],
            ['accountant@newpos.test', 'محاسب تجريبي', 'accountant', 'accountant'],
            ['store@newpos.test', 'أمين مخزن تجريبي', 'store', 'storekeeper'],
        ] as [$email, $name, $username, $role]) {
            $user = User::query()->firstOrCreate(['email' => $email], [
                'name' => $name, 'username' => $username, 'password' => 'password', 'pin' => '1234',
            ]);
            $user->syncRoles([$role]);
            if ($role === 'cashier') {
                $user->givePermissionTo('pos.credit_sale'); // demo: this cashier may sell on credit to registered customers
            }
            $user->locations()->syncWithoutDetaching([$location->id]);
        }
    }

    private function contacts(): void
    {
        $numbers = app(DocumentNumberGenerator::class);
        $wholesale = CustomerGroup::query()->where('name', 'عملاء الجملة')->first();

        $contacts = [
            [ContactType::Customer, 'أحمد محمود', null, '01001234567', null],
            [ContactType::Customer, 'سارة علي', null, '01112345678', null],
            [ContactType::Customer, 'محلات النور', 'محلات النور للتجارة', '01223456789', $wholesale?->id],
            [ContactType::Supplier, 'شركة الأمل للتوريدات', 'الأمل للتوريدات', '0223456789', null],
            [ContactType::Supplier, 'مؤسسة الفجر', 'الفجر للاستيراد', '0233344455', null],
            [ContactType::Both, 'مخازن السلام', 'السلام', '01098765432', null],
        ];

        foreach ($contacts as [$type, $name, $business, $mobile, $groupId]) {
            if (Contact::query()->where('mobile', $mobile)->exists()) {
                continue; // don't burn a contact code on re-runs
            }
            Contact::query()->create([
                'mobile' => $mobile,
                'type' => $type,
                'code' => $numbers->next(DocumentType::Contact),
                'name' => $name,
                'business_name' => $business,
                'customer_group_id' => $groupId,
                'credit_limit' => $type === ContactType::Supplier ? null : 5000,
                'city' => 'القاهرة',
            ]);
        }
    }

    private function catalog(): void
    {
        if (Product::query()->exists()) {
            return;
        }

        $piece = Unit::query()->where('name', 'قطعة')->firstOrFail();
        $kg = Unit::query()->where('name', 'كيلوجرام')->firstOrFail();
        $carton = Unit::query()->where('name', 'كرتونة')->firstOrFail();
        $vat = TaxRate::query()->where('rate', 14)->first();
        $wholesale = PriceGroup::query()->where('name', 'سعر الجملة')->first();
        $locations = Location::query()->pluck('id');

        $brands = collect(['جهينة', 'إيديتا', 'سامسونج', 'أديداس', 'محلي'])
            ->mapWithKeys(fn ($name) => [$name => Brand::query()->firstOrCreate(['name' => $name])]);

        $food = Category::query()->firstOrCreate(['name' => 'مواد غذائية', 'parent_id' => null]);
        $dairy = Category::query()->firstOrCreate(['name' => 'ألبان', 'parent_id' => $food->id]);
        $snacks = Category::query()->firstOrCreate(['name' => 'سناكس', 'parent_id' => $food->id]);
        $electronics = Category::query()->firstOrCreate(['name' => 'إلكترونيات', 'parent_id' => null]);
        $clothes = Category::query()->firstOrCreate(['name' => 'ملابس', 'parent_id' => null]);
        $services = Category::query()->firstOrCreate(['name' => 'خدمات', 'parent_id' => null]);

        // [name, sku, category, brand, unit, cost, price, barcode, tax]
        $singles = [
            ['لبن جهينة 1 لتر', 'MLK-001', $dairy, 'جهينة', $piece, 28, 35, '6223000000011', null],
            ['زبادي جهينة', 'YOG-001', $dairy, 'جهينة', $piece, 6, 8, '6223000000028', null],
            ['جبنة بيضاء (وزن)', 'CHS-001', $dairy, 'محلي', $kg, 120, 160, null, null],
            ['مولتو كرواسون', 'SNK-001', $snacks, 'إيديتا', $piece, 8, 10, '6223000000035', null],
            ['تايجر شيبسي', 'SNK-002', $snacks, 'إيديتا', $piece, 4, 5, '6223000000042', null],
            ['سماعة بلوتوث', 'ELC-001', $electronics, 'سامسونج', $piece, 450, 650, '8801000000019', $vat],
            ['شاحن سريع 25 وات', 'ELC-002', $electronics, 'سامسونج', $piece, 300, 420, '8801000000026', $vat],
        ];

        foreach ($singles as [$name, $sku, $category, $brand, $unit, $cost, $price, $barcode, $tax]) {
            $product = Product::query()->create([
                'name' => $name, 'sku' => $sku, 'type' => ProductType::Single,
                'unit_id' => $unit->id, 'brand_id' => $brands[$brand]->id, 'category_id' => $category->id,
                'tax_rate_id' => $tax?->id, 'tax_type' => TaxType::Inclusive, 'alert_qty' => 5,
            ]);
            if ($unit->is($piece) && $category->is($snacks)) {
                $product->units()->sync([$carton->id]); // sell by carton too
            }
            $variation = $product->variations()->create([
                'name' => 'DEFAULT', 'sku' => $sku, 'is_default' => true,
                'purchase_price' => $cost, 'purchase_price_inc_tax' => $cost,
                'profit_margin' => round(($price - $cost) / $cost * 100, 2),
                'sell_price' => $price, 'sell_price_inc_tax' => $price,
            ]);
            if ($barcode) {
                $variation->barcodes()->create(['barcode' => $barcode]);
            }
            if ($wholesale) {
                $variation->prices()->create(['price_group_id' => $wholesale->id, 'price_type' => 'fixed', 'value' => round($price * 0.9, 2)]);
            }
            $product->locations()->sync($locations);
        }

        // variable product: T-shirt sizes x colors
        $sizes = VariationTemplate::query()->firstOrCreate(['name' => 'المقاس']);
        foreach (['S', 'M', 'L', 'XL'] as $i => $v) {
            $sizes->values()->firstOrCreate(['value' => $v], ['sort' => $i]);
        }
        $colors = VariationTemplate::query()->firstOrCreate(['name' => 'اللون']);
        foreach (['أبيض', 'أسود'] as $i => $v) {
            $colors->values()->firstOrCreate(['value' => $v], ['sort' => $i]);
        }

        $shirt = Product::query()->create([
            'name' => 'تيشيرت رياضي', 'sku' => 'TSH-001', 'type' => ProductType::Variable,
            'unit_id' => $piece->id, 'brand_id' => $brands['أديداس']->id, 'category_id' => $clothes->id,
            'tax_type' => TaxType::Inclusive, 'alert_qty' => 3,
        ]);
        $shirt->attributes()->create(['name' => 'المقاس', 'variation_template_id' => $sizes->id, 'sort' => 0]);
        $shirt->attributes()->create(['name' => 'اللون', 'variation_template_id' => $colors->id, 'sort' => 1]);
        $n = 0;
        foreach (['أبيض', 'أسود'] as $color) {
            foreach (['S', 'M', 'L', 'XL'] as $size) {
                $n++;
                $shirt->variations()->create([
                    'name' => "{$size} / {$color}", 'attribute_values' => ['المقاس' => $size, 'اللون' => $color],
                    'sku' => sprintf('TSH-001-%02d', $n), 'is_default' => $n === 1, 'sort' => $n,
                    'purchase_price' => 180, 'purchase_price_inc_tax' => 180, 'profit_margin' => 66.67,
                    'sell_price' => 300, 'sell_price_inc_tax' => 300,
                ]);
            }
        }
        $shirt->locations()->sync($locations);

        // service (no stock)
        $service = Product::query()->create([
            'name' => 'خدمة توصيل', 'sku' => 'SRV-001', 'type' => ProductType::Service, 'track_stock' => false,
            'unit_id' => $piece->id, 'category_id' => $services->id, 'tax_type' => TaxType::Inclusive,
        ]);
        $service->variations()->create([
            'name' => 'DEFAULT', 'sku' => 'SRV-001', 'is_default' => true,
            'sell_price' => 30, 'sell_price_inc_tax' => 30,
        ]);
        $service->locations()->sync($locations);
    }

    private function openingStock(): void
    {
        if (StockAdjustment::query()->exists()) {
            return;
        }

        $lines = Variation::query()->with('product')->get()
            ->filter(fn (Variation $v) => $v->product->tracksStock())
            ->map(fn (Variation $v, int $i) => new StockLineData(
                variationId: $v->id,
                unitId: $v->product->unit_id,
                qty: (string) (10 + ($i * 7) % 40),
                unitCost: (string) $v->purchase_price,
            ))
            ->values()->all();

        app(CreateStockAdjustment::class)->handle(
            Location::query()->firstOrFail()->id, StockAdjustmentType::Opening, $lines,
            now()->startOfMonth(), reason: 'رصيد افتتاحي تجريبي',
        );
    }

    private function purchasing(): void
    {
        if (Purchase::query()->exists()) {
            return;
        }
        $supplier = Contact::query()->where('mobile', '0223456789')->firstOrFail();
        $location = Location::query()->firstOrFail();
        $lines = Variation::query()->with('product')->whereIn('sku', ['MLK-001', 'SNK-001', 'SNK-002'])->get()
            ->map(fn (Variation $v) => new PurchaseLineData($v->id, $v->product->unit_id, '24', (string) $v->purchase_price))
            ->values()->all();

        $purchase = app(CreatePurchase::class)->handle(new PurchaseData(
            supplierId: $supplier->id, locationId: $location->id, lines: $lines, date: now()->subDays(3),
            supplierRef: 'INV-7781', shippingCharges: '60',
        ));

        app(RecordPayment::class)->handle(new PaymentData(
            direction: PaymentDirection::Out,
            paymentMethodId: PaymentMethod::query()->where('type', 'cash')->value('id'),
            amount: '500', contactId: $supplier->id, locationId: $location->id, allocations: [[$purchase, '500']],
        ));

        $customer = Contact::query()->where('mobile', '01223456789')->firstOrFail();
        if (! $customer->openingBalances()->exists()) {
            app(RecordOpeningBalance::class)->handle($customer, '1250',
                BalanceDirection::Receivable, now()->startOfMonth()->toDateString());
        }
    }

    /** A closed shift of the demo cashier with a few sales (cash, card, credit) and one return. */
    private function sales(): void
    {
        if (Sale::query()->exists()) {
            return;
        }
        $cashier = User::query()->where('email', 'cashier@newpos.test')->firstOrFail();
        $register = CashRegister::query()->firstOrFail();
        $previous = auth()->user();
        auth()->setUser($cashier);

        $session = app(OpenCashSession::class)->handle($register, $cashier, 200);
        $method = fn (string $type) => PaymentMethod::query()->where('type', $type)->value('id');
        $v = fn (string $sku) => Variation::query()->where('sku', $sku)->firstOrFail();
        $sell = fn (array $lines, array $tenders, ?int $customerId = null) => app(SaveSale::class)->handle(
            new SaleData(
                locationId: $register->location_id,
                lines: array_map(fn ($l) => new SaleLineData($v($l[0])->id, (string) $l[1]), $lines),
                tenders: $tenders, customerId: $customerId, cashSessionId: $session->id,
            ));

        $first = $sell([['MLK-001', 2], ['SNK-001', 3]], [['payment_method_id' => $method('cash'), 'amount' => 100]]);
        $sell([['ELC-001', 1]], [['payment_method_id' => $method('card'), 'amount' => 650]]);
        $sell([['TSH-001-02', 2], ['SNK-002', 5]], [], Contact::query()->where('mobile', '01223456789')->value('id'));

        app(CreateSaleReturn::class)->handle($first,
            [['sale_item_id' => $first->items()->first()->id, 'qty' => 1]], $method('cash'), $session->id);

        $expected = app(CashSessionSummary::class)->expectedCash($session);
        app(CloseCashSession::class)->handle($session, (string) $expected->minus(5), note: 'عجز 5 جنيه (تجريبي)');

        $previous ? auth()->setUser($previous) : auth()->forgetUser();
    }

    /** Owner's capital, a bank deposit, paid and unpaid expenses and a general receipt voucher. */
    private function finance(): void
    {
        if (Expense::query()->exists()) {
            return;
        }
        $account = fn (AccountSubType $role) => Account::query()->where('sub_type', $role)->orderBy('code')->value('id');
        $location = Location::query()->firstOrFail()->id;
        $cash = PaymentMethod::query()->where('type', 'cash')->value('id');
        $category = fn (string $code) => ExpenseCategory::query()->where('code', $code)->value('id');

        app(RecordJournalEntry::class)->handle(now()->subDays(30), 'رأس المال المدفوع', [
            ['account_id' => $account(AccountSubType::Cash), 'debit' => 20000],
            ['account_id' => $account(AccountSubType::Capital), 'credit' => 20000],
        ]);
        app(TransferBetweenAccounts::class)->handle($account(AccountSubType::Cash), $account(AccountSubType::Bank), 10000, now()->subDays(20), 'إيداع في البنك');

        app(CreateExpense::class)->handle(['location_id' => $location, 'expense_category_id' => $category('5201'), 'amount' => 3000,
            'expense_date' => now()->subDays(10), 'note' => 'إيجار الشهر'], ['payment_method_id' => $cash]);
        app(CreateExpense::class)->handle(['location_id' => $location, 'expense_category_id' => $category('5203'), 'amount' => 450,
            'expense_date' => now()->subDays(3), 'note' => 'فاتورة كهرباء — لم تُدفع بعد']);

        app(RecordPayment::class)->handle(new PaymentData(
            direction: PaymentDirection::In, paymentMethodId: $cash, amount: '150', locationId: $location,
            paidAt: now()->subDay(), counterAccountId: $account(AccountSubType::OtherIncome), note: 'بيع كراتين فارغة',
        ));
    }
}
