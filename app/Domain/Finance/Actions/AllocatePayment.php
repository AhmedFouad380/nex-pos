<?php

namespace App\Domain\Finance\Actions;

use App\Domain\CRM\Models\Contact;
use App\Domain\CRM\Services\ContactLedger;
use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Services\PayableDocuments;
use App\Domain\Shared\Services\PaymentStatusRefresher;
use App\Support\Decimal;
use App\Support\DocumentNumber;
use App\Support\DomainException;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/** Allocates (more of) a payment to documents — also used to apply a contact's advance to a new invoice. */
final class AllocatePayment
{
    public function __construct(
        private readonly PaymentStatusRefresher $statuses,
        private readonly ContactLedger $ledger,
    ) {}

    /** @param list<array{0: Model, 1: string|int|float}> $allocations */
    public function handle(Payment $payment, array $allocations = [], bool $autoAllocate = false): Payment
    {
        if ($payment->voided_at !== null) {
            throw new DomainException(__('finance.errors.payment_voided'));
        }

        return DB::transaction(function () use ($payment, $allocations, $autoAllocate) {
            $payment = Payment::query()->lockForUpdate()->findOrFail($payment->id);
            $free = $this->unallocated($payment);
            $touched = [];

            foreach ($allocations as [$document, $amount]) {
                $amount = Decimal::of($amount);
                if (! $amount->isPositive()) {
                    continue;
                }
                $this->assertAllocatable($payment, $document);
                $document = $document::query()->lockForUpdate()->findOrFail($document->getKey());
                if ($amount->isGreaterThan(Decimal::of($document->getAttribute('due_total')))) {
                    throw new DomainException(__('finance.errors.over_allocation', ['number' => $this->numberOf($document)]));
                }
                if ($amount->isGreaterThan($free)) {
                    throw new DomainException(__('finance.errors.exceeds_payment'));
                }
                $this->allocate($payment, $document, $amount);
                $free = $free->minus($amount);
                $touched[] = $document;
            }

            if ($autoAllocate && $free->isPositive() && $payment->contact_id) {
                foreach (PayableDocuments::open(Contact::query()->findOrFail($payment->contact_id), $payment->direction) as $document) {
                    if (! $free->isPositive()) {
                        break;
                    }
                    $take = BigDecimal::min($free, Decimal::of($document->getAttribute('due_total')));
                    $this->allocate($payment, $document, $take);
                    $free = $free->minus($take);
                    $touched[] = $document;
                }
            }

            foreach ($touched as $document) {
                $this->statuses->refresh($document);
            }
            if ($payment->contact_id) {
                $this->ledger->refresh(Contact::query()->findOrFail($payment->contact_id));
            }

            return $payment;
        });
    }

    public function unallocated(Payment $payment): BigDecimal
    {
        return Decimal::of($payment->amount)->minus(Decimal::of($payment->allocations()->sum('amount')));
    }

    private function allocate(Payment $payment, Model $document, BigDecimal $amount): void
    {
        $existing = $payment->allocations()
            ->where('allocatable_type', $document->getMorphClass())->where('allocatable_id', $document->getKey())->first();

        $existing
            ? $existing->update(['amount' => Decimal::toDb(Decimal::of($existing->amount)->plus($amount))])
            : $payment->allocations()->create([
                'allocatable_type' => $document->getMorphClass(),
                'allocatable_id' => $document->getKey(),
                'amount' => Decimal::toDb($amount),
            ]);
    }

    private function assertAllocatable(Payment $payment, Model $document): void
    {
        $ok = array_key_exists($document::class, PayableDocuments::TYPES)
            && $document->getAttribute('voided_at') === null
            && PayableDocuments::directionFor($document) === $payment->direction
            && ($payment->contact_id === null || PayableDocuments::contactIdOf($document) === $payment->contact_id);

        if (! $ok) {
            throw new DomainException(__('finance.errors.not_allocatable', ['number' => $this->numberOf($document)]));
        }
    }

    private function numberOf(Model $document): string
    {
        return DocumentNumber::of($document) ?? (string) $document->getKey();
    }
}
