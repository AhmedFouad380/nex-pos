<?php

use App\Domain\Finance\Enums\AccountSubType as Role;
use App\Domain\Finance\Enums\PaymentDirection;
use App\Domain\Finance\Models\Account;
use App\Domain\Finance\Models\Expense;
use App\Domain\Finance\Models\ExpenseCategory;
use App\Domain\Finance\Models\Payment;
use App\Domain\Foundation\Models\Location;
use App\Domain\Shared\Enums\PaymentStatus;
use App\Domain\Wallet\Actions\ConfirmWalletTransaction;
use App\Domain\Wallet\Actions\IngestWalletMessage;
use App\Domain\Wallet\Actions\ReadWalletMessageAgain;
use App\Domain\Wallet\Actions\RejectWalletTransaction;
use App\Domain\Wallet\Enums\WalletProvider;
use App\Domain\Wallet\Enums\WalletTarget;
use App\Domain\Wallet\Enums\WalletTransactionStatus as Status;
use App\Domain\Wallet\Models\Wallet;
use App\Domain\Wallet\Models\WalletDevice;
use App\Domain\Wallet\Models\WalletMessageTemplate;
use App\Domain\Wallet\Models\WalletTransaction;
use App\Domain\Wallet\Services\TemplateCompiler;
use App\Domain\Wallet\Services\WalletSuggestions;
use App\Models\User;
use App\Support\DomainException;
use Illuminate\Support\Facades\DB;

const VF_IN = "تم استلام مبلغ 1500.00 جنيه من 01096660484؛\nالمسجل بإسم محمد ابراهيم محمد احمد سالم\nعلى رقم محفظتك 01099638383 بتاريخ 14:43 26-11-16.\nرصيدك الحالي: 7161.52 جنيه\nرقم العملية: 025845786581\n تقدر تتابع كل مصروفاتك من تاريخ المعاملات على أبلكيشن أنا فودافون http://vf";

// the wording since November 2026 ("من رقم", the date on its own line)
const VF_IN_NEW = "تم استلام مبلغ 20000 جنيه من رقم 01090755510 المسجل بإسم Mohsen A Mahmoud على رقم محفظتك  01099638383.\nرصيدك الحالي: 41100.14 جنيه\nتاريخ العملية: 00:05 26-11-23\nرقم العملية: 026077094210\nتابع كل مصروفاتك من تاريخ المعاملات على أبلكيشن أنا فودافون http://vf.eg/vfcash";

const VF_OUT = "تم تحويل 655 جنيه لرقم 01097746217 مصاريف الخدمة 1 جنيه رصيد حسابك فى فودافون كاش الحالي 4.52.\nتاريخ العملية 17:20 26-11-16 :\nرقم العملية 025851509705 :\n حول فلوس لأي محفظة وزود فرصك تكسب جنيه دهب! وكمان هيجيلك لحد 100ج كاش باك. http://vf.eg/vfcash?id=money_transfer";

beforeEach(function () {
    seedBaseData();
    $this->location = Location::query()->firstOrFail();
    $this->admin = User::query()->firstOrFail();
    $this->device = WalletDevice::query()->create(['name' => 'موبايل الفرع', 'location_id' => $this->location->id]);
    $this->wallet = Wallet::query()->create(['provider' => WalletProvider::VodafoneCash, 'name' => 'فودافون الفرع',
        'phone' => '01099638383', 'location_id' => $this->location->id]);
    $this->ingest = fn (string $body, ?WalletDevice $device = null) => app(IngestWalletMessage::class)->handle($device ?? $this->device, $body, 'VF-Cash')[0];
});

it('reads both Vodafone Cash messages with the seeded templates', function () {
    $in = ($this->ingest)(VF_IN);
    expect($in->status)->toBe(Status::Pending)
        ->and($in->direction)->toBe(PaymentDirection::In)
        ->and($in->amount)->toBe('1500.0000')
        ->and($in->counterparty_phone)->toBe('01096660484')
        ->and($in->counterparty_name)->toBe('محمد ابراهيم محمد احمد سالم')
        ->and($in->wallet_phone)->toBe('01099638383')
        ->and($in->wallet_id)->toBe($this->wallet->id)
        ->and($in->balance)->toBe('7161.5200')
        ->and($in->reference)->toBe('025845786581')
        ->and($in->occurred_at->format('Y-m-d H:i'))->toBe('2026-11-16 14:43');

    $new = ($this->ingest)(VF_IN_NEW);
    expect($new->status)->toBe(Status::Pending)
        ->and($new->amount)->toBe('20000.0000')
        ->and($new->counterparty_phone)->toBe('01090755510')
        ->and($new->counterparty_name)->toBe('Mohsen A Mahmoud')
        ->and($new->wallet_id)->toBe($this->wallet->id)
        ->and($new->balance)->toBe('41100.1400')
        ->and($new->reference)->toBe('026077094210')
        ->and($new->occurred_at->format('Y-m-d H:i'))->toBe('2026-11-23 00:05');
    // a message with a missing space ("تاريخالعملية") still reads
    $squeezed = ($this->ingest)(str_replace(['تاريخ العملية', '026077094210', '20000'], ['تاريخالعملية', '026077094211', '30'], VF_IN_NEW));
    expect($squeezed->amount)->toBe('30.0000');

    $out = ($this->ingest)(VF_OUT);
    expect($out->direction)->toBe(PaymentDirection::Out)
        ->and($out->amount)->toBe('655.0000')
        ->and($out->fee)->toBe('1.0000')
        ->and($out->counterparty_phone)->toBe('01097746217')
        ->and($out->wallet_id)->toBe($this->wallet->id) // the branch's only Vodafone wallet
        ->and($out->reference)->toBe('025851509705')
        ->and($out->occurred_at->format('Y-m-d H:i'))->toBe('2026-11-16 17:20');

    expect($this->wallet->refresh()->last_balance)->toBe('41100.1400'); // the latest message's balance (Nov 23)

    // nothing is posted until someone confirms
    expect(Payment::query()->count())->toBe(0)
        ->and($this->admin->notifications()->count())->toBe(4);
});

it('stores the same SMS from two phones once and flags a repeated reference', function () {
    $other = WalletDevice::query()->create(['name' => 'موبايل 2', 'location_id' => $this->location->id]);
    $first = ($this->ingest)(VF_IN);
    [$again, $new] = app(IngestWalletMessage::class)->handle($other, '  '.VF_IN."\n", 'VF-Cash');
    expect($new)->toBeFalse()->and($again->id)->toBe($first->id);

    $reworded = ($this->ingest)(str_replace('تقدر تتابع', 'تابع', VF_IN));
    expect($reworded->status)->toBe(Status::Duplicate);
});

it('keeps messages no template understands and reads them again once one is added', function () {
    $body = 'تم استلام 250.50 جنيه من 01112223334 رقم العملية 99887766';
    $tx = app(IngestWalletMessage::class)->handle($this->device, $body, 'Etisalat')[0];
    expect($tx->status)->toBe(Status::Unparsed);

    WalletMessageTemplate::query()->create(['provider' => WalletProvider::EtisalatCash, 'direction' => 'in', 'name' => 'اتصالات - استلام',
        'sender' => 'Etisalat', 'template' => 'تم استلام {amount} جنيه من {phone} رقم العملية {reference}']);
    expect(app(ReadWalletMessageAgain::class)->handle())->toBe(1)
        ->and($tx->refresh()->status)->toBe(Status::Pending)
        ->and($tx->amount)->toBe('250.5000')
        ->and($tx->provider)->toBe(WalletProvider::EtisalatCash);
});

it('compiles templates with Arabic digits and rejects bad ones', function () {
    $template = new WalletMessageTemplate(['template' => 'تم استلام {amount} جنيه من {phone}', 'date_format' => 'H:i y-m-d']);
    expect(app(TemplateCompiler::class)->parse($template, 'تم استلام ١٬٢٥٠٫٥٠ جنيه من ٠١٠٩٦٦٦٠٤٨٤')?->amount)->toBe('1250.50');

    expect(fn () => app(TemplateCompiler::class)->compile('من {phone}'))->toThrow(DomainException::class)
        ->and(fn () => app(TemplateCompiler::class)->compile('{amount} {amout}'))->toThrow(DomainException::class);
});

it('confirms money in against a customer and settles their invoice', function () {
    $customer = makeCustomer(['mobile' => '01096660484']);
    $this->actingAs($this->admin);
    $item = makeVariation([], ['purchase_price' => 100, 'sell_price_inc_tax' => 1500, 'sell_price' => 1500]);
    makePurchase(makeSupplier(), [[$item, 5, 100]]);
    $sale = sell([[$item, 1]], [], ['cashSessionId' => openSession()->id, 'customerId' => $customer->id]);
    $tx = ($this->ingest)(VF_IN);

    $suggestions = app(WalletSuggestions::class)->for($tx);
    expect(collect($suggestions)->pluck('id')->all())->toContain($customer->id);

    app(ConfirmWalletTransaction::class)->handle($tx, WalletTarget::Contact, $customer->id, $this->admin);

    $tx->refresh();
    expect($tx->status)->toBe(Status::Confirmed)
        ->and($tx->payment->number)->toStartWith('RV-')
        ->and($tx->payment->account_id)->toBe(Account::query()->where('code', '1103')->value('id'))
        ->and($sale->refresh()->payment_status)->toBe(PaymentStatus::Paid)
        ->and(fn () => app(ConfirmWalletTransaction::class)->handle($tx, WalletTarget::Contact, $customer->id, $this->admin))
        ->toThrow(DomainException::class);
});

it('confirms money out as a supplier payment and books the provider fee', function () {
    $supplier = makeSupplier();
    $tx = ($this->ingest)(VF_OUT);

    app(ConfirmWalletTransaction::class)->handle($tx, WalletTarget::Contact, $supplier->id, $this->admin, note: 'دفعة');

    $tx->refresh();
    expect($tx->payment->direction)->toBe(PaymentDirection::Out)
        ->and($tx->payment->amount)->toBe('655.0000')
        ->and($supplier->refresh()->balance)->toBe('655.0000')
        ->and(Expense::query()->find($tx->fee_expense_id)->total)->toBe('1.0000')
        ->and(Expense::query()->find($tx->fee_expense_id)->expenseCategory->name)->toBe(ConfirmWalletTransaction::FEES_CATEGORY)
        ->and((float) DB::table('journal_lines')->where('account_id', Account::query()->where('code', '1103')->value('id'))
            ->sum(DB::raw('debit - credit')))->toEqualWithDelta(-656, 0.001); // the wallets account: 655 + 1 fee
});

it('confirms money out as an expense or against another account', function () {
    $tx = ($this->ingest)(VF_OUT);
    $rent = ExpenseCategory::query()->where('name', 'إيجار')->firstOrFail();
    app(ConfirmWalletTransaction::class)->handle($tx, WalletTarget::Expense, $rent->id, $this->admin);
    expect(Expense::query()->find($tx->refresh()->expense_id)->total)->toBe('655.0000');

    $in = ($this->ingest)(VF_IN);
    $otherIncome = Account::query()->where('sub_type', Role::OtherIncome)->firstOrFail();
    expect(fn () => app(ConfirmWalletTransaction::class)->handle($in, WalletTarget::Expense, $rent->id, $this->admin))
        ->toThrow(DomainException::class) // expenses are money out
        ->and(fn () => app(ConfirmWalletTransaction::class)->handle($in, WalletTarget::Account,
            Account::query()->where('sub_type', Role::Receivable)->value('id'), $this->admin))->toThrow(DomainException::class);
    app(ConfirmWalletTransaction::class)->handle($in, WalletTarget::Account, $otherIncome->id, $this->admin);
    expect(accountBalance(Role::OtherIncome))->toEqualWithDelta(-1500, 0.001);
});

it('needs a wallet and permission to confirm, and can reject instead', function () {
    $this->wallet->update(['phone' => null]);
    Wallet::query()->create(['provider' => WalletProvider::VodafoneCash, 'name' => 'تاني', 'location_id' => $this->location->id]);
    $tx = ($this->ingest)(VF_OUT); // two Vodafone wallets in the branch, no number in the message
    expect($tx->wallet_id)->toBeNull()
        ->and(fn () => app(ConfirmWalletTransaction::class)->handle($tx, WalletTarget::Contact, makeSupplier()->id, $this->admin))
        ->toThrow(DomainException::class);

    $storekeeper = User::factory()->create();
    $storekeeper->assignRole('storekeeper');
    expect(fn () => app(RejectWalletTransaction::class)->handle($tx, $storekeeper, 'x'))->toThrow(DomainException::class);

    app(RejectWalletTransaction::class)->handle($tx, $this->admin, 'تحويل شخصي');
    expect($tx->refresh()->status)->toBe(Status::Rejected)
        ->and(WalletTransaction::query()->where('status', Status::Pending)->count())->toBe(0);
});
