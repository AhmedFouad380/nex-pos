<?php

use App\Domain\Finance\Enums\AccountSubType as Role;
use App\Domain\Finance\Models\Account;
use App\Domain\Finance\Models\AccountTransfer;
use App\Domain\Finance\Models\Expense;
use App\Domain\Finance\Models\ExpenseCategory;
use App\Domain\Finance\Models\JournalEntry;
use App\Domain\Finance\Models\Payment;
use App\Domain\Foundation\Models\Location;
use App\Filament\Admin\Pages\TrialBalance;
use App\Filament\Admin\Resources\Accounts\Pages\AccountLedger;
use App\Filament\Admin\Resources\Accounts\Pages\ManageAccounts;
use App\Filament\Admin\Resources\AccountTransfers\Pages\ManageAccountTransfers;
use App\Filament\Admin\Resources\ExpenseCategories\Pages\ManageExpenseCategories;
use App\Filament\Admin\Resources\Expenses\Pages\CreateExpense;
use App\Filament\Admin\Resources\Expenses\Pages\ListExpenses;
use App\Filament\Admin\Resources\Expenses\Pages\ViewExpense;
use App\Filament\Admin\Resources\JournalEntries\Pages\CreateJournalEntry;
use App\Filament\Admin\Resources\JournalEntries\Pages\ListJournalEntries;
use App\Filament\Admin\Resources\JournalEntries\Pages\ViewJournalEntry;
use App\Filament\Admin\Resources\ReceiptVouchers\Pages\ListReceiptVouchers;
use App\Models\User;
use Filament\Facades\Filament;

use function Pest\Livewire\livewire;

beforeEach(function () {
    seedBaseData();
    $this->actingAs(User::query()->firstOrFail());
    Filament::setCurrentPanel('admin');
    $this->location = Location::query()->firstOrFail();
    $this->id = fn (Role $role) => Account::query()->where('sub_type', $role)->orderBy('code')->value('id');
});

it('shows the chart of accounts with balances and adds a bank account', function () {
    $tea = makeVariation();
    makePurchase(makeSupplier(), [[$tea, 10, 6]]);
    $inventory = Account::query()->where('sub_type', Role::Inventory)->firstOrFail();

    livewire(ManageAccounts::class)->assertOk()->assertCanSeeTableRecords([$inventory])->assertSee('60.00')
        ->callAction('create', ['parent_id' => Account::query()->where('code', '11')->value('id'), 'code' => '1120',
            'name' => 'بنك الأهلي', 'type' => 'asset', 'sub_type' => 'bank', 'is_postable' => true, 'is_active' => true])
        ->assertHasNoActionErrors();

    expect(Account::query()->where('code', '1120')->firstOrFail()->sub_type)->toBe(Role::Bank);
    livewire(AccountLedger::class, ['record' => $inventory->id])->assertOk()->assertSee('PU-00001');
});

it('records a manual journal entry from the form and reverses it', function () {
    livewire(CreateJournalEntry::class)
        ->fillForm(['entry_date' => now()->toDateString(), 'description' => 'إيداع رأس المال', 'lines' => [
            ['account_id' => ($this->id)(Role::Cash), 'debit' => 1000, 'credit' => null, 'memo' => null],
            ['account_id' => ($this->id)(Role::Capital), 'debit' => null, 'credit' => 1000, 'memo' => null],
        ]])
        ->call('create')->assertHasNoFormErrors();

    $entry = JournalEntry::query()->sole();
    livewire(ListJournalEntries::class)->assertOk()->assertCanSeeTableRecords([$entry]);
    livewire(ViewJournalEntry::class, ['record' => $entry->id])->assertOk()->assertSee('إيداع رأس المال')->callAction('reverse');

    expect(accountBalance(Role::Capital))->toBe(0.0);
});

it('refuses an unbalanced manual entry from the form', function () {
    livewire(CreateJournalEntry::class)
        ->fillForm(['entry_date' => now()->toDateString(), 'description' => 'x', 'lines' => [
            ['account_id' => ($this->id)(Role::Cash), 'debit' => 100],
            ['account_id' => ($this->id)(Role::Capital), 'credit' => 90],
        ]])
        ->call('create')->assertNotified(__('finance.errors.unbalanced', ['difference' => '10.00']));

    expect(JournalEntry::query()->count())->toBe(0);
});

it('creates an expense paid from the cash box, pays the rest later and voids it', function () {
    $category = ExpenseCategory::query()->firstOrFail();
    livewire(CreateExpense::class)
        ->fillForm(['location_id' => $this->location->id, 'expense_category_id' => $category->id, 'amount' => 500,
            'expense_date' => now()->toDateTimeString(), 'pay_now' => true, 'payment_method_id' => cashMethodId(), 'paid_amount' => 200])
        ->call('create')->assertHasNoFormErrors();

    $expense = Expense::query()->sole();
    expect((string) $expense->due_total)->toBe('300.0000');

    livewire(ListExpenses::class)->assertOk()->assertCanSeeTableRecords([$expense]);
    livewire(ViewExpense::class, ['record' => $expense->id])->assertOk()
        ->callAction('pay', ['amount' => 300, 'payment_method_id' => cashMethodId(), 'paid_at' => now()->toDateTimeString()]);
    expect($expense->refresh()->due_total)->toBe('0.0000')->and(accountBalance(Role::Cash))->toBe(-500.0);

    livewire(ViewExpense::class, ['record' => $expense->id])->callAction('void', ['reason' => 'مكرر']);
    expect($expense->refresh()->voided_at)->not->toBeNull()->and(accountBalance(Role::Cash))->toBe(0.0);
    livewire(ManageExpenseCategories::class)->assertOk()->assertCanSeeTableRecords([$category]);
});

it('transfers between cash and bank and voids the transfer', function () {
    livewire(ManageAccountTransfers::class)->assertOk()
        ->callAction('create', ['from_account_id' => ($this->id)(Role::Cash), 'to_account_id' => ($this->id)(Role::Bank),
            'amount' => 250, 'transferred_at' => now()->toDateTimeString()])
        ->assertHasNoActionErrors();

    $transfer = AccountTransfer::query()->sole();
    expect(accountBalance(Role::Bank))->toBe(250.0);

    livewire(ManageAccountTransfers::class)->callTableAction('void', $transfer);
    expect($transfer->refresh()->voided_at)->not->toBeNull()->and(accountBalance(Role::Bank))->toBe(0.0);
});

it('issues a receipt voucher and prints it with the amount in words', function () {
    $customer = makeCustomer();
    livewire(ListReceiptVouchers::class)->assertOk()
        ->callAction('newVoucher', ['party' => 'customer', 'contact_id' => $customer->id, 'location_id' => $this->location->id,
            'amount' => 1250.5, 'payment_method_id' => cashMethodId(), 'paid_at' => now()->toDateTimeString(), 'note' => 'دفعة مقدمة'])
        ->assertHasNoActionErrors();

    $payment = Payment::query()->sole();
    expect($payment->contact_id)->toBe($customer->id);

    $this->get(route('vouchers.print', $payment))->assertOk()
        ->assertSee($payment->number)->assertSee('فقط ألف ومائتان وخمسون جنيه مصري وخمسون قرش لا غير')->assertSee('دفعة مقدمة');
});

it('shows a balanced trial balance', function () {
    $tea = makeVariation([], ['sell_price' => 10, 'sell_price_inc_tax' => 10]);
    makePurchase(makeSupplier(), [[$tea, 10, 6]]);
    sell([[$tea, 2]], [cash(20)], ['cashSessionId' => openSession()->id]);

    livewire(TrialBalance::class)->assertOk()->assertSee(__('Balanced'))->assertSee('60.00')->assertDontSee(__('Not balanced'));
});

it('keeps cashiers and storekeepers out of the books', function () {
    foreach (['cashier', 'storekeeper'] as $role) {
        $user = User::factory()->create();
        $user->assignRole($role);
        $this->actingAs($user);

        $this->get(TrialBalance::getUrl())->assertForbidden();
        $this->get(route('filament.admin.resources.journal-entries.index'))->assertForbidden();
        $this->get(route('filament.admin.resources.expenses.index'))->assertForbidden();
    }

    $accountant = User::factory()->create();
    $accountant->assignRole('accountant');
    $this->actingAs($accountant)->get(TrialBalance::getUrl())->assertOk();
});
