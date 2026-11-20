<?php

namespace App\Domain\Payroll\Actions;

use App\Domain\CRM\Enums\ContactType;
use App\Domain\CRM\Models\Contact;
use App\Domain\Finance\Actions\RecordPayment;
use App\Domain\Finance\Data\PaymentData;
use App\Domain\Finance\Enums\PaymentDirection;
use App\Domain\Foundation\Services\DocumentNumberGenerator;
use App\Domain\Payroll\Enums\AdvanceStatus;
use App\Domain\Payroll\Models\EmployeeAdvance;
use App\Domain\Shared\Enums\DocumentType;
use App\Support\Decimal;
use App\Support\DomainException;
use Brick\Math\BigDecimal;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Pays an advance (سلفة) to an employee: a payment voucher debits their account (they owe it back),
 * and the advance row tracks the monthly installment and what is left.
 */
final class GiveAdvance
{
    public function __construct(
        private readonly DocumentNumberGenerator $numbers,
        private readonly RecordPayment $payments,
    ) {}

    /**
     * @param  array{contact_id: int, location_id: int, amount: string|int|float, payment_method_id: int,
     *     installment_amount?: string|int|float|null, advance_date?: ?CarbonInterface, account_id?: ?int,
     *     cash_session_id?: ?int, note?: ?string}  $data
     */
    public function handle(array $data): EmployeeAdvance
    {
        $amount = Decimal::of($data['amount']);
        if (! $amount->isPositive()) {
            throw new DomainException(__('finance.errors.non_positive_amount'));
        }
        $installment = filled($data['installment_amount'] ?? null) ? Decimal::of($data['installment_amount']) : null;
        if ($installment !== null && ! $installment->isPositive()) {
            throw new DomainException(__('finance.errors.non_positive_amount'));
        }
        $employee = Contact::query()->findOrFail($data['contact_id']);
        if ($employee->type !== ContactType::Employee) {
            throw new DomainException(__('payroll.errors.not_employee'));
        }
        $date = $data['advance_date'] ?? now();

        return DB::transaction(function () use ($data, $amount, $installment, $employee, $date) {
            $number = $this->numbers->next(DocumentType::EmployeeAdvance, $data['location_id']);
            $payment = $this->payments->handle(new PaymentData(
                direction: PaymentDirection::Out,
                paymentMethodId: $data['payment_method_id'],
                amount: Decimal::toDb($amount),
                contactId: $employee->id,
                locationId: $data['location_id'],
                paidAt: $date,
                accountId: $data['account_id'] ?? null,
                cashSessionId: $data['cash_session_id'] ?? null,
                reference: $number,
                note: collect([__('payroll.advance_note', ['number' => $number]), $data['note'] ?? null])->filter()->implode(' - '),
            ));

            return EmployeeAdvance::query()->create([
                'number' => $number,
                'contact_id' => $employee->id,
                'location_id' => $data['location_id'],
                'payment_id' => $payment->id,
                'advance_date' => $date,
                'amount' => Decimal::toDb($amount),
                'installment_amount' => $installment ? Decimal::toDb(BigDecimal::min($installment, $amount)) : null,
                'remaining' => Decimal::toDb($amount),
                'status' => AdvanceStatus::Open,
                'note' => $data['note'] ?? null,
                'created_by' => auth()->id(),
            ]);
        });
    }
}
