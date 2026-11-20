<?php

namespace App\Domain\Finance\Actions;

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
            $accountId = $data->accountId ?? $this->accountFor($method, $data->locationId);

            $payment = Payment::query()->create([
                'number' => $this->numbers->next(
                    $data->direction === PaymentDirection::In ? DocumentType::ReceiptVoucher : DocumentType::PaymentVoucher,
                    $data->locationId,
                ),
                'direction' => $data->direction,
                'contact_id' => $data->contactId,
                'counter_account_id' => $data->counterAccountId,
                'location_id' => $data->locationId,
                'cash_session_id' => $data->cashSessionId,
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

    private function accountFor(PaymentMethod $method, ?int $locationId): int
    {
        $accountId = $locationId
            ? DB::table('location_payment_method')->where('location_id', $locationId)->where('payment_method_id', $method->id)->value('account_id')
            : null;
        $accountId ??= $method->account_id;

        return $accountId ?? throw new DomainException(__('finance.errors.method_without_account', ['method' => $method->name]));
    }
}
