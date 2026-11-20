<?php

namespace App\Domain\Finance\Actions;

use App\Domain\CRM\Models\Contact;
use App\Domain\CRM\Services\ContactLedger;
use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Services\Posting;
use App\Domain\Payroll\Models\EmployeeAdvance;
use App\Domain\Payroll\Models\PayrollLine;
use App\Domain\Payroll\Services\PayrollTotals;
use App\Domain\Shared\Services\PaymentStatusRefresher;
use App\Support\DomainException;
use Illuminate\Support\Facades\DB;

/** Cancels a payment: its allocations stop counting, so the documents become due again. */
final class VoidPayment
{
    public function __construct(
        private readonly PaymentStatusRefresher $statuses,
        private readonly ContactLedger $ledger,
    ) {}

    public function handle(Payment $payment, string $reason): Payment
    {
        if ($payment->voided_at !== null) {
            throw new DomainException(__('finance.errors.payment_voided'));
        }
        $advance = EmployeeAdvance::query()->whereNull('voided_at')
            ->where(fn ($query) => $query->where('payment_id', $payment->id)
                ->orWhereHas('settlements', fn ($q) => $q->where('payment_id', $payment->id)))
            ->value('number');
        if ($advance !== null) {
            throw new DomainException(__('payroll.errors.void_from_advance', ['number' => $advance]));
        }

        return DB::transaction(function () use ($payment, $reason) {
            $payment->update(['voided_at' => now(), 'void_reason' => $reason, 'voided_by' => auth()->id()]);

            foreach ($payment->allocations()->with('allocatable')->get() as $allocation) {
                if ($allocation->allocatable) {
                    $this->statuses->refresh($allocation->allocatable);
                }
            }
            if ($payment->contact_id) {
                $this->ledger->refresh(Contact::query()->findOrFail($payment->contact_id));
            }

            app(Posting::class)->sync($payment);

            // a salary paid by this voucher is unpaid again
            foreach (PayrollLine::query()->where('payment_id', $payment->id)->with('run')->get() as $line) {
                $line->update(['payment_id' => null]);
                app(PayrollTotals::class)->refresh($line->run);
            }

            return $payment;
        });
    }
}
