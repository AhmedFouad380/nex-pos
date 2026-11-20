<?php

use App\Domain\CashManagement\Actions\OpenCashSession;
use App\Domain\CashManagement\Models\CashRegister;
use App\Domain\CashManagement\Models\CashSession;
use App\Domain\Catalog\Enums\ProductType;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Unit;
use App\Domain\Catalog\Models\Variation;
use App\Domain\CRM\Actions\SaveContact;
use App\Domain\CRM\Models\Contact;
use App\Domain\Finance\Enums\AccountSubType;
use App\Domain\Finance\Models\Account;
use App\Domain\Finance\Models\PaymentMethod;
use App\Domain\Foundation\Models\Location;
use App\Domain\Inventory\Models\StockLayer;
use App\Domain\Inventory\Models\StockLevel;
use App\Domain\Inventory\Models\StockMovement;
use App\Domain\Purchasing\Actions\CreatePurchase;
use App\Domain\Purchasing\Data\PurchaseData;
use App\Domain\Purchasing\Data\PurchaseLineData;
use App\Domain\Purchasing\Models\Purchase;
use App\Domain\Sales\Actions\SaveSale;
use App\Domain\Sales\Data\SaleData;
use App\Domain\Sales\Data\SaleLineData;
use App\Domain\Sales\Models\Sale;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    // every feature test that posts documents must leave the general ledger consistent
    ->afterEach(function () {
        if (! ($this->skipLedgerCheck ?? false) && Account::query()->exists()) {
            assertLedgerConsistent();
        }
    })
    ->in('Feature');

/** Seed the base data every install has. */
function seedBaseData(): void
{
    test()->seed(DatabaseSeeder::class);
}

/** A stock-tracked single product with its default variation. */
function makeVariation(array $product = [], array $variation = []): Variation
{
    static $n = 0;
    $n++;
    $unit = Unit::query()->where('name', 'قطعة')->first()
        ?? Unit::query()->create(['name' => 'قطعة', 'short_name' => 'ق']);

    $p = Product::query()->create([
        'name' => "Item {$n}", 'sku' => "SKU-{$n}", 'type' => ProductType::Single,
        'unit_id' => $unit->id, ...$product,
    ]);

    return $p->variations()->create(['name' => 'DEFAULT', 'sku' => "SKU-{$n}", 'purchase_price' => 1, ...$variation])->load('product');
}

/** Asserts the ledger invariants for every stock level: cache == SUM(movements) == SUM(layers) (when not negative). */
function assertStockConsistent(): void
{
    foreach (StockLevel::query()->get() as $level) {
        $movements = StockMovement::query()
            ->where('variation_id', $level->variation_id)->where('location_id', $level->location_id)->sum('qty');
        $layers = StockLayer::query()
            ->where('variation_id', $level->variation_id)->where('location_id', $level->location_id)->sum('remaining_qty');

        expect((float) $level->qty_on_hand)->toEqualWithDelta((float) $movements, 0.0001);
        if ((float) $level->qty_on_hand >= 0) {
            expect((float) $layers)->toEqualWithDelta((float) $level->qty_on_hand, 0.0001);
        }
    }
}

function makeSupplier(array $attributes = []): Contact
{
    return app(SaveContact::class)->handle(['type' => 'supplier', 'name' => 'مورد '.uniqid(), ...$attributes]);
}

function makeCustomer(array $attributes = []): Contact
{
    return app(SaveContact::class)->handle(['type' => 'customer', 'name' => 'عميل '.uniqid(), ...$attributes]);
}

/** @param list<array{0: Variation, 1: string|int, 2: string|int}> $lines [variation, qty, unit cost] */
function makePurchase(Contact $supplier, array $lines, array $overrides = []): Purchase
{
    return app(CreatePurchase::class)->handle(new PurchaseData(...[
        'supplierId' => $supplier->id,
        'locationId' => Location::query()->firstOrFail()->id,
        'lines' => array_map(fn ($l) => new PurchaseLineData($l[0]->id, $l[0]->product->unit_id, (string) $l[1], (string) $l[2]), $lines),
        ...$overrides,
    ]));
}

function cashMethodId(): int
{
    return PaymentMethod::query()->where('type', 'cash')->value('id');
}

function openSession(?User $user = null, float $float = 0): CashSession
{
    $user ??= auth()->user() ?? User::query()->firstOrFail();
    $register = CashRegister::query()->firstOrFail();

    return app(OpenCashSession::class)->handle($register, $user, $float);
}

/** @param list<array{0: Variation, 1: string|int|float}> $lines [variation, qty] at catalog price */
function sell(array $lines, array $tenders = [], array $overrides = []): Sale
{
    return app(SaveSale::class)->handle(new SaleData(...[
        'locationId' => Location::query()->firstOrFail()->id,
        'lines' => array_map(fn ($l) => new SaleLineData($l[0]->id, (string) $l[1], ...($l[2] ?? [])), $lines),
        'tenders' => $tenders,
        ...$overrides,
    ]));
}

function cash(float|int $amount): array
{
    return ['payment_method_id' => cashMethodId(), 'amount' => $amount];
}

function card(float|int $amount): array
{
    return ['payment_method_id' => PaymentMethod::query()->where('type', 'card')->value('id'), 'amount' => $amount];
}

/** Signed balance (debit − credit) of the system account with this role. */
function accountBalance(AccountSubType $role): float
{
    $id = Account::query()->where('sub_type', $role)->orderBy('code')->value('id');

    return (float) DB::table('journal_lines')->where('account_id', $id)->sum(DB::raw('debit - credit'));
}

/**
 * General-ledger invariants: every entry balances; Inventory = stock valuation; Receivable − Payable =
 * Σ contact balances; goods in transit = pending purchases; accrued expenses = unpaid expenses.
 */
function assertLedgerConsistent(): void
{
    $unbalanced = DB::table('journal_lines')->groupBy('journal_entry_id')
        ->havingRaw('abs(sum(debit) - sum(credit)) > 0.00001')->pluck('journal_entry_id');
    expect($unbalanced->all())->toBe([]);

    $stockValue = (float) DB::table('stock_movements')->sum(DB::raw('qty * unit_cost'));
    expect(accountBalance(AccountSubType::Inventory))->toEqualWithDelta($stockValue, 0.01);

    $contacts = (float) DB::table('contacts')->sum('balance');
    expect(accountBalance(AccountSubType::Receivable) + accountBalance(AccountSubType::Payable) + accountBalance(AccountSubType::EmployeeAccounts))
        ->toEqualWithDelta($contacts, 0.01);

    $pending = (float) DB::table('purchases')->whereNull('voided_at')->where('status', '!=', 'received')
        ->sum(DB::raw('(total - tax_amount) * exchange_rate'));
    expect(accountBalance(AccountSubType::GoodsInTransit))->toEqualWithDelta($pending, 0.01);

    $unpaid = (float) DB::table('expenses')->whereNull('voided_at')->sum('due_total');
    expect(-accountBalance(AccountSubType::AccruedExpenses))->toEqualWithDelta($unpaid, 0.01);
}

/** An employee with salary data at the first location. */
function makeEmployee(string $salary, array $profile = []): Contact
{
    $employee = app(SaveContact::class)->handle(['type' => 'employee', 'name' => 'موظف '.uniqid()]);
    $employee->employeeProfile()->create(['location_id' => Location::query()->firstOrFail()->id, 'base_salary' => $salary, ...$profile]);

    return $employee->refresh();
}
