<?php

use App\Domain\CashManagement\Actions\CloseCashSession;
use App\Domain\CRM\Actions\CreateLedgerDiscount;
use App\Domain\CRM\Actions\RecordOpeningBalance;
use App\Domain\CRM\Actions\SaveContact;
use App\Domain\CRM\Enums\BalanceDirection;
use App\Domain\Finance\Actions\CreateExpense;
use App\Domain\Finance\Actions\RecordJournalEntry;
use App\Domain\Finance\Actions\SaveAccount;
use App\Domain\Finance\Actions\TransferBetweenAccounts;
use App\Domain\Finance\Actions\VoidExpense;
use App\Domain\Finance\Actions\VoidPayment;
use App\Domain\Finance\Enums\AccountSubType as Role;
use App\Domain\Finance\Enums\PaymentDirection;
use App\Domain\Finance\Models\Account;
use App\Domain\Finance\Models\ExpenseCategory;
use App\Domain\Finance\Models\JournalEntry;
use App\Domain\Finance\Services\GeneralLedger;
use App\Domain\Foundation\Models\Location;
use App\Domain\Inventory\Actions\CreateStockAdjustment;
use App\Domain\Inventory\Data\StockLineData;
use App\Domain\Inventory\Enums\StockAdjustmentType;
use App\Domain\Purchasing\Actions\CreatePurchaseReturn;
use App\Domain\Purchasing\Actions\ReceivePurchase;
use App\Domain\Purchasing\Enums\PurchaseStatus;
use App\Domain\Sales\Actions\CreateSaleReturn;
use App\Domain\Sales\Actions\VoidSale;
use App\Domain\Shared\Enums\PaymentStatus;
use App\Filament\Admin\Resources\Employees\EmployeeResource;
use App\Filament\Admin\Resources\Suppliers\SupplierResource;
use App\Filament\Admin\Support\PaymentActions;
use App\Models\User;
use App\Support\ArabicAmountInWords;
use App\Support\DomainException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    seedBaseData();
    $this->actingAs(User::query()->firstOrFail());
    $this->location = Location::query()->firstOrFail();
    $this->tea = makeVariation([], ['purchase_price' => 6, 'sell_price' => 10, 'sell_price_inc_tax' => 10]);
    $this->supplier = makeSupplier();
    $this->id = fn (Role $role) => Account::query()->where('sub_type', $role)->orderBy('code')->value('id');
});

it('posts a purchase, a cash sale and its cost, and reverses the sale when voided', function () {
    makePurchase($this->supplier, [[$this->tea, 10, 6]]);
    expect(accountBalance(Role::Inventory))->toBe(60.0)->and(accountBalance(Role::Payable))->toBe(-60.0);

    $session = openSession();
    $sale = sell([[$this->tea, 2]], [cash(20)], ['cashSessionId' => $session->id]);

    expect(accountBalance(Role::Cash))->toBe(20.0)
        ->and(accountBalance(Role::Sales))->toBe(-20.0)
        ->and(accountBalance(Role::Cogs))->toBe(12.0)
        ->and(accountBalance(Role::Inventory))->toBe(48.0)
        ->and(accountBalance(Role::Receivable))->toBe(0.0);

    app(VoidSale::class)->handle($sale, 'خطأ');

    expect(accountBalance(Role::Cash))->toBe(0.0)->and(accountBalance(Role::Sales))->toBe(0.0)
        ->and(accountBalance(Role::Inventory))->toBe(60.0)
        ->and(JournalEntry::query()->whereNotNull('reversed_entry_id')->count())->toBe(2); // sale + its receipt
});

it('keeps a pending purchase in goods in transit until it is received', function () {
    $purchase = makePurchase($this->supplier, [[$this->tea, 4, 3]], ['status' => PurchaseStatus::Pending]);
    expect(accountBalance(Role::GoodsInTransit))->toBe(12.0)->and(accountBalance(Role::Inventory))->toBe(0.0);

    app(ReceivePurchase::class)->handle($purchase);

    expect(accountBalance(Role::GoodsInTransit))->toBe(0.0)->and(accountBalance(Role::Inventory))->toBe(12.0)
        ->and(accountBalance(Role::Payable))->toBe(-12.0);
});

it('posts sale and purchase returns with their stock', function () {
    $purchase = makePurchase($this->supplier, [[$this->tea, 10, 6]]);
    $session = openSession();
    $sale = sell([[$this->tea, 3]], [cash(30)], ['cashSessionId' => $session->id]);

    app(CreateSaleReturn::class)->handle($sale, [['sale_item_id' => $sale->items()->value('id'), 'qty' => 1]], cashMethodId(), $session->id);
    expect(accountBalance(Role::SalesReturn))->toBe(10.0)->and(accountBalance(Role::Cash))->toBe(20.0)
        ->and(accountBalance(Role::Cogs))->toBe(12.0)->and(accountBalance(Role::Inventory))->toBe(48.0);

    app(CreatePurchaseReturn::class)->handle([['purchase_item_id' => $purchase->items()->value('id'), 'qty' => 2]], $purchase);
    expect(accountBalance(Role::Inventory))->toBe(36.0)->and(accountBalance(Role::Payable))->toBe(-48.0);
});

it('records an expense paid now, one paid later in part, and voids it', function () {
    $rent = ExpenseCategory::query()->where('code', '5201')->firstOrFail();
    app(CreateExpense::class)->handle(['location_id' => $this->location->id, 'expense_category_id' => $rent->id, 'amount' => 1000],
        ['payment_method_id' => cashMethodId()]);

    expect(DB::table('journal_lines')->where('account_id', $rent->account_id)->sum('debit'))->toEqual(1000)
        ->and(accountBalance(Role::Cash))->toBe(-1000.0)
        ->and(accountBalance(Role::AccruedExpenses))->toBe(0.0);

    $bill = app(CreateExpense::class)->handle(['location_id' => $this->location->id, 'amount' => 300]);
    app(CreateExpense::class)->pay($bill, cashMethodId(), 100);
    expect($bill->refresh()->payment_status)->toBe(PaymentStatus::Partial)
        ->and(accountBalance(Role::AccruedExpenses))->toBe(-200.0)
        ->and(accountBalance(Role::OperatingExpense))->toBe(300.0); // no category → miscellaneous

    app(VoidExpense::class)->handle($bill, 'مكرر');
    expect(accountBalance(Role::AccruedExpenses))->toBe(0.0)->and(accountBalance(Role::OperatingExpense))->toBe(0.0)
        ->and(accountBalance(Role::Cash))->toBe(-1000.0);
});

it('moves money between accounts and voids the transfer', function () {
    $transfer = app(TransferBetweenAccounts::class)->handle(($this->id)(Role::Cash), ($this->id)(Role::Bank), 500);
    expect(accountBalance(Role::Bank))->toBe(500.0)->and(accountBalance(Role::Cash))->toBe(-500.0)
        ->and(fn () => app(TransferBetweenAccounts::class)->handle(($this->id)(Role::Cash), ($this->id)(Role::Cash), 1))->toThrow(DomainException::class);

    app(TransferBetweenAccounts::class)->void($transfer);
    expect(accountBalance(Role::Bank))->toBe(0.0);
});

it('accepts balanced manual entries only, keeps control accounts out, and reverses them', function () {
    $capital = ($this->id)(Role::Capital);
    $cash = ($this->id)(Role::Cash);
    $post = fn (array $lines) => app(RecordJournalEntry::class)->handle(now(), 'رأس مال', $lines);

    expect(fn () => $post([['account_id' => $cash, 'debit' => 100], ['account_id' => $capital, 'credit' => 90]]))->toThrow(DomainException::class)
        ->and(fn () => $post([['account_id' => ($this->id)(Role::Receivable), 'debit' => 100], ['account_id' => $capital, 'credit' => 100]]))->toThrow(DomainException::class)
        ->and(fn () => $post([['account_id' => Account::query()->where('code', '1')->value('id'), 'debit' => 1], ['account_id' => $capital, 'credit' => 1]]))->toThrow(DomainException::class);

    $entry = $post([['account_id' => $cash, 'debit' => 5000], ['account_id' => $capital, 'credit' => 5000]]);
    expect($entry->is_manual)->toBeTrue()->and(accountBalance(Role::Capital))->toBe(-5000.0);

    app(RecordJournalEntry::class)->reverse($entry);
    expect(accountBalance(Role::Capital))->toBe(0.0)
        ->and(fn () => app(RecordJournalEntry::class)->reverse($entry))->toThrow(DomainException::class);
});

it('posts opening balances, account discounts, opening stock and a cash shortage', function () {
    $customer = makeCustomer();
    app(RecordOpeningBalance::class)->handle($customer, '400', BalanceDirection::Receivable, now()->toDateString());
    app(RecordOpeningBalance::class)->handle($this->supplier, '250', BalanceDirection::Payable, now()->toDateString());
    app(CreateLedgerDiscount::class)->handle($customer, '40', BalanceDirection::Receivable);
    app(CreateStockAdjustment::class)->handle($this->location->id, StockAdjustmentType::Opening,
        [new StockLineData($this->tea->id, $this->tea->product->unit_id, '5', '6')]);

    expect(accountBalance(Role::Receivable))->toBe(360.0)
        ->and(accountBalance(Role::SalesDiscount))->toBe(40.0)
        ->and(accountBalance(Role::OpeningBalance))->toBe(-400.0 + 250.0 - 30.0)
        ->and(accountBalance(Role::Inventory))->toBe(30.0);

    $session = openSession(null, 100);
    app(CloseCashSession::class)->handle($session, 95);
    expect(accountBalance(Role::CashOverShort))->toBe(5.0)->and(accountBalance(Role::Cash))->toBe(-5.0);
});

it('rebuilds the same ledger from the documents', function () {
    makePurchase($this->supplier, [[$this->tea, 10, 6]]);
    $session = openSession();
    $sale = sell([[$this->tea, 2]], [cash(20)], ['cashSessionId' => $session->id]);
    sell([[$this->tea, 1]], [cash(10)], ['cashSessionId' => $session->id]);
    app(VoidPayment::class)->handle($sale->allocations()->firstOrFail()->payment, 'x');
    app(CreateExpense::class)->handle(['location_id' => $this->location->id, 'amount' => 75], ['payment_method_id' => cashMethodId()]);
    app(RecordJournalEntry::class)->handle(now(), 'يدوي', [['account_id' => ($this->id)(Role::Cash), 'debit' => 9], ['account_id' => ($this->id)(Role::Capital), 'credit' => 9]]);

    $before = app(GeneralLedger::class)->trialBalance()->map(fn ($r) => [$r['account']->code, $r['closing']])->all();
    Artisan::call('ledger:rebuild', ['--force' => true]);
    $after = app(GeneralLedger::class)->trialBalance()->map(fn ($r) => [$r['account']->code, $r['closing']])->all();

    expect($after)->toBe($before)
        ->and(JournalEntry::query()->where('is_manual', true)->count())->toBe(1)
        ->and(JournalEntry::query()->whereNotNull('reversed_entry_id')->count())->toBe(0); // voided documents simply don't post
});

it('builds account ledgers and a balanced trial balance', function () {
    makePurchase($this->supplier, [[$this->tea, 10, 6]]);
    $session = openSession();
    sell([[$this->tea, 2]], [cash(20)], ['cashSessionId' => $session->id]);

    $cash = Account::query()->findOrFail(($this->id)(Role::Cash));
    $ledger = app(GeneralLedger::class)->statement($cash);
    expect($ledger['rows'])->toHaveCount(1)->and($ledger['closing'])->toBe('20.0000');

    $assets = Account::query()->where('code', '1')->firstOrFail();
    expect(app(GeneralLedger::class)->balance($assets)->toFloat())->toBe(68.0); // cash 20 + stock 48

    $rows = app(GeneralLedger::class)->trialBalance()->filter(fn ($r) => $r['account']->is_postable);
    expect($rows->sum(fn ($r) => (float) $r['debit']))->toEqual($rows->sum(fn ($r) => (float) $r['credit']));
});

it('manages the chart of accounts without breaking it', function () {
    $banks = Account::query()->where('code', '11')->firstOrFail();
    $new = app(SaveAccount::class)->handle(['parent_id' => $banks->id, 'code' => '1150', 'name' => 'بنك مصر', 'type' => 'liability', 'sub_type' => 'bank']);
    expect($new->type->value)->toBe('asset'); // a child takes its parent's type

    $cashBox = Account::query()->where('code', '1101')->firstOrFail();
    app(TransferBetweenAccounts::class)->handle($cashBox->id, $new->id, 10);

    expect(fn () => app(SaveAccount::class)->handle(['parent_id' => $cashBox->id, 'code' => '110101', 'name' => 'x', 'type' => 'asset']))->toThrow(DomainException::class) // has entries
        ->and(fn () => app(SaveAccount::class)->delete($cashBox))->toThrow(DomainException::class)
        ->and(fn () => app(SaveAccount::class)->delete($new))->toThrow(DomainException::class);

    app(SaveAccount::class)->handle(['code' => '1101', 'name' => 'الخزنة', 'type' => 'income', 'sub_type' => null], $cashBox);
    expect($cashBox->refresh()->name)->toBe('الخزنة')->and($cashBox->sub_type)->toBe(Role::Cash)->and($cashBox->type->value)->toBe('asset');
});

it('writes amounts in Arabic words', function () {
    expect(ArabicAmountInWords::money(1250.5))->toBe('فقط ألف ومائتان وخمسون جنيه مصري وخمسون قرش لا غير')
        ->and(ArabicAmountInWords::money(3000))->toBe('فقط ثلاثة آلاف جنيه مصري لا غير')
        ->and(ArabicAmountInWords::money(2500000))->toBe('فقط مليونان وخمسمائة ألف جنيه مصري لا غير')
        ->and(ArabicAmountInWords::money('0.75'))->toBe('فقط خمسة وسبعون قرش لا غير')
        ->and(ArabicAmountInWords::money(21, 'SAR'))->toBe('فقط واحد وعشرون ريال سعودي لا غير');
});

it('keeps employees in their own control account and screen', function () {
    $employee = app(SaveContact::class)->handle(['type' => 'employee', 'name' => 'عصام خالد']);
    PaymentActions::record(PaymentDirection::Out, $employee->id, $this->location->id,
        ['payment_method_id' => cashMethodId(), 'amount' => 500, 'paid_at' => now()]);

    expect(accountBalance(Role::EmployeeAccounts))->toBe(500.0)
        ->and(accountBalance(Role::Receivable))->toBe(0.0)
        ->and($employee->refresh()->balance)->toBe('500.0000') // the advance he owes us
        ->and(fn () => makePurchase($employee, [[$this->tea, 1, 6]]))->toThrow(DomainException::class);

    $this->get(EmployeeResource::getUrl())->assertOk()->assertSee('عصام خالد');
    $this->get(SupplierResource::getUrl())->assertOk()->assertDontSee('عصام خالد');
});
