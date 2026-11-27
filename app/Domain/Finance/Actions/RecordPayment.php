<?php

namespace App\Domain\Finance\Actions;

use App\Domain\CashManagement\Services\ShiftLink;
use App\Domain\Finance\Data\PaymentData;
use App\Domain\Finance\Enums\PaymentDirection;
use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Models\PaymentMethod;
use App\Domain\Finance\Services\Posting;
use App\Domain\Foundation\Services\DocumentNumberGenerator;
use App\Domain\Shared\Enums\DocumentType;
use App\Support\Decimal;
use App\Support\DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Records money received (receipt voucher, RV-) or paid (payment voucher, PV-) and allocates it to
 * documents. Whatever is not allocated stays on the contact as an advance.
 */
final class RecordPayment
{
    public function __construct(private readonly DocumentNumberGenerator $numbers) {}

    public function handle(PaymentData $data): Payment
    {
        $amount = Decimal::of($data->amount);
        if (! $amount->isPositive()) {
            throw new DomainException(__('finance.errors.non_positive_amount'));
        }
        if ($data->contactId === null && $data->counterAccountId === null) {
            throw new DomainException(__('finance.errors.contact_or_account_required'));
        }

        return DB::transaction(function () use ($data, $amount) {
            $method = PaymentMethod::query()->findOrFail($data->paymentMethodId);
            $branchAccountId = $data->accountId === null ? $this->accountFor($method, $data->locationId) : $this->branchAccount($method, $data->locationId);
            $accountId = $data->accountId ?? $branchAccountId;
            $cashSessionId = $data->cashSessionId
                ?? app(ShiftLink::class)->resolve($data->locationId, $method, $accountId, $branchAccountId, $data->paidAt);

            $payment = Payment::query()->create([
                'number' => $this->numbers->next(
                    $data->direction === PaymentDirection::In ? DocumentType::ReceiptVoucher : DocumentType::PaymentVoucher,
                    $data->locationId,
                ),
                'direction' => $data->direction,
                'contact_id' => $data->contactId,
                'counter_account_id' => $data->counterAccountId,
                'location_id' => $data->locationId,
                'cash_session_id' => $cashSessionId,
                'payment_method_id' => $method->id,
                'account_id' => $accountId,
                'amount' => Decimal::toDb($amount),
                'paid_at' => $data->paidAt ?? now(),
                'reference' => $data->reference,
                'cheque_number' => $data->chequeNumber,
                'cheque_date' => $data->chequeDate,
                'bank_name' => $data->bankName,
                'note' => $data->note,
                'created_by' => auth()->id(),
            ]);

            app(AllocatePayment::class)->handle($payment, $data->allocations, $data->autoAllocate);

            app(Posting::class)->sync($payment);

            return $payment->refresh();
        });
    }

    /** The money account a method uses at a location (its branch account, else its own). */
    public function accountFor(PaymentMethod $method, ?int $locationId): int
    {
        return $this->branchAccount($method, $locationId) ?? throw new DomainException(__('finance.errors.method_without_account', ['method' => $method->name]));
    }

    private function branchAccount(PaymentMethod $method, ?int $locationId): ?int
    {
        $accountId = $locationId
            ? DB::table('location_payment_method')->where('location_id', $locationId)->where('payment_method_id', $method->id)->value('account_id')
            : null;

        return ($accountId ?? $method->account_id) === null ? null : (int) ($accountId ?? $method->account_id);
    }
}
