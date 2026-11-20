<?php

use App\Domain\CRM\Actions\CreateLedgerDiscount;
use App\Domain\CRM\Enums\BalanceDirection;
use App\Domain\CRM\Services\ContactLedger;
use App\Domain\Finance\Actions\AllocatePayment;
use App\Domain\Finance\Actions\RecordPayment;
use App\Domain\Finance\Actions\VoidPayment;
use App\Domain\Finance\Data\PaymentData;
use App\Domain\Finance\Enums\PaymentDirection as Dir;
use App\Domain\Finance\Models\Payment;
use App\Domain\Foundation\Models\Location;
use App\Domain\Purchasing\Actions\CreatePurchaseReturn;
use App\Domain\Shared\Enums\PaymentStatus;
use App\Support\DomainException;

beforeEach(function () {
    seedBaseData();
    $this->location = Location::query()->firstOrFail();
    $this->supplier = makeSupplier();
    $this->item = makeVariation();
    $this->pay = fn (string $amount, array $alloc = [], bool $auto = false, Dir $dir = Dir::Out, $contact = null) => app(RecordPayment::class)->handle(
        new PaymentData($dir, cashMethodId(), $amount, ($contact ?? $this->supplier)->id, $this->location->id, allocations: $alloc, autoAllocate: $auto));
});

it('pays a purchase partly then fully, with RV/PV numbers', function () {
    $purchase = makePurchase($this->supplier, [[$this->item, 10, 10]]); // 100

    $p1 = ($this->pay)('30', [[$purchase, '30']]);
    expect($p1->number)->toBe('PV-00001')
        ->and($purchase->refresh()->payment_status)->toBe(PaymentStatus::Partial)
        ->and($purchase->due_total)->toBe('70.0000');

    ($this->pay)('70', [[$purchase, '70']]);
    expect($purchase->refresh()->payment_status)->toBe(PaymentStatus::Paid)
        ->and($this->supplier->refresh()->balance)->toBe('0.0000');
});

it('spreads a lump payment over the oldest open documents and keeps the rest as advance', function () {
    $old = makePurchase($this->supplier, [[$this->item, 1, 40]], ['date' => now()->subDays(5)]);
    $new = makePurchase($this->supplier, [[$this->item, 1, 60]]);

    $payment = ($this->pay)('120', auto: true);

    expect($old->refresh()->payment_status)->toBe(PaymentStatus::Paid)
        ->and($new->refresh()->payment_status)->toBe(PaymentStatus::Paid)
        ->and((string) app(AllocatePayment::class)->unallocated($payment))->toBe('20.0000')
        ->and($this->supplier->refresh()->balance)->toBe('20.0000'); // supplier now owes us 20 (advance)

    // the advance is applied to the next purchase
    $next = makePurchase($this->supplier, [[$this->item, 1, 50]]);
    app(AllocatePayment::class)->handle($payment, [[$next, '20']]);
    expect($next->refresh()->due_total)->toBe('30.0000');
});

it('rejects over-allocation, wrong direction and other contacts\' documents', function () {
    $purchase = makePurchase($this->supplier, [[$this->item, 1, 10]]);

    expect(fn () => ($this->pay)('50', [[$purchase, '20']]))->toThrow(DomainException::class)      // more than due
        ->and(fn () => ($this->pay)('5', [[$purchase, '10']]))->toThrow(DomainException::class)    // more than the payment
        ->and(fn () => ($this->pay)('10', [[$purchase, '10']], dir: Dir::In))->toThrow(DomainException::class) // receipts can't pay purchases
        ->and(fn () => ($this->pay)('10', [[$purchase, '10']], contact: makeSupplier()))->toThrow(DomainException::class);

    expect(Payment::query()->count())->toBe(0); // every failure rolled back
});

it('makes documents due again when a payment is voided', function () {
    $purchase = makePurchase($this->supplier, [[$this->item, 1, 10]]);
    $payment = ($this->pay)('10', [[$purchase, '10']]);

    app(VoidPayment::class)->handle($payment, 'شيك مرتد');

    expect($purchase->refresh()->payment_status)->toBe(PaymentStatus::Due)
        ->and($this->supplier->refresh()->balance)->toBe('-10.0000');
});

it('builds an account statement with opening balance, documents, payments and discounts', function () {
    $supplier = makeSupplier(['opening_balance' => 500, 'opening_balance_date' => now()->subMonth()->toDateString()]);
    $purchase = makePurchase($supplier, [[$this->item, 10, 30]], ['date' => now()->subDays(10)]);     // −300
    ($this->pay)('200', auto: true, contact: $supplier);                                            // +200 → pays the opening balance first
    $this->travel(1)->seconds();
    app(CreateLedgerDiscount::class)->handle($supplier, '50', BalanceDirection::Payable);           // +50
    $this->travel(1)->seconds();
    app(CreatePurchaseReturn::class)->handle(
        [['purchase_item_id' => $purchase->items->sole()->id, 'qty' => 1]], $purchase);              // +30

    $statement = app(ContactLedger::class)->statement($supplier);

    expect($statement['rows']->pluck('kind')->all())->toBe(['opening_balance', 'purchase', 'payment', 'ledger_discount', 'purchase_return'])
        ->and($statement['closing'])->toBe('-520.0000')  // −500 −300 +200 +50 +30
        ->and($supplier->refresh()->balance)->toBe('-520.0000')
        ->and($supplier->openingBalances->sole()->due_total)->toBe('300.0000');

    // a date range starts from the carried-forward balance
    $recent = app(ContactLedger::class)->statement($supplier, now()->subDays(1));
    // carried forward = −500 opening − 300 purchase (both before the range)
    expect($recent['opening'])->toBe('-800.0000')->and($recent['closing'])->toBe('-520.0000');
});
