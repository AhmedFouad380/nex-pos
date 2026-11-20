<?php

namespace App\Domain\Payroll\Actions;

use App\Domain\CRM\Enums\ContactType;
use App\Domain\CRM\Models\Contact;
use App\Domain\Payroll\Enums\AdvanceRequestStatus;
use App\Domain\Payroll\Models\AdvanceRequest;
use App\Domain\Payroll\Models\EmployeeAdvance;
use App\Domain\Payroll\Services\AdvanceRequestNotifier;
use App\Models\User;
use App\Support\Decimal;
use App\Support\DomainException;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * طلب سلفة: the employee asks → a manager (advances.approve) approves or rejects → whoever pays advances
 * (advances.create) gives it, which records the payment voucher and the advance through GiveAdvance.
 */
final class AdvanceRequestWorkflow
{
    public function __construct(private readonly GiveAdvance $give, private readonly AdvanceRequestNotifier $notifier) {}

    /** The employee asks for an advance (for themselves, or a manager on behalf of an employee). */
    public function request(User $by, Contact $employee, string|int|float $amount, string|int|float|null $installment = null, ?string $reason = null): AdvanceRequest
    {
        if ($employee->type !== ContactType::Employee) {
            throw new DomainException(__('payroll.errors.not_employee'));
        }
        $this->assertAmounts($amount, $installment);
        if (AdvanceRequest::query()->where('contact_id', $employee->id)->where('status', AdvanceRequestStatus::Pending)->exists()) {
            throw new DomainException(__('payroll.errors.request_pending'));
        }

        $request = AdvanceRequest::query()->create([
            'contact_id' => $employee->id,
            'location_id' => $employee->employeeProfile?->location_id,
            'amount' => Decimal::toDb(Decimal::of($amount)),
            'installment_amount' => filled($installment) ? Decimal::toDb(Decimal::of($installment)) : null,
            'reason' => filled($reason) ? $reason : null,
            'requested_by' => $by->id,
        ]);
        rescue(fn () => $this->notifier->requested($request));

        return $request;
    }

    /** A manager approves, possibly with another amount or installment. */
    public function approve(AdvanceRequest $request, User $by, string|int|float|null $amount = null, string|int|float|null $installment = null, ?string $note = null): AdvanceRequest
    {
        $this->assertStatus($request, AdvanceRequestStatus::Pending, 'payroll.errors.request_not_pending');
        $amount = filled($amount) ? $amount : (string) $request->amount;
        $installment = filled($installment) ? $installment : ($request->installment_amount !== null ? (string) $request->installment_amount : null);
        $this->assertAmounts($amount, $installment);

        $request->update([
            'status' => AdvanceRequestStatus::Approved,
            'approved_amount' => Decimal::toDb(Decimal::of($amount)),
            'approved_installment' => $installment !== null ? Decimal::toDb(Decimal::of($installment)) : null,
            'decided_by' => $by->id, 'decided_at' => now(), 'decision_note' => $note,
        ]);
        rescue(fn () => $this->notifier->approved($request));

        return $request;
    }

    public function reject(AdvanceRequest $request, User $by, ?string $note = null): AdvanceRequest
    {
        $this->assertStatus($request, AdvanceRequestStatus::Pending, 'payroll.errors.request_not_pending');
        $request->update(['status' => AdvanceRequestStatus::Rejected, 'decided_by' => $by->id, 'decided_at' => now(), 'decision_note' => $note]);
        rescue(fn () => $this->notifier->rejected($request));

        return $request;
    }

    /** The employee withdraws a request nobody decided on yet. */
    public function cancel(AdvanceRequest $request): AdvanceRequest
    {
        $this->assertStatus($request, AdvanceRequestStatus::Pending, 'payroll.errors.request_not_pending');
        $request->update(['status' => AdvanceRequestStatus::Cancelled]);

        return $request;
    }

    /** Pays an approved request: the payment voucher and the advance are recorded as for any advance. */
    public function pay(AdvanceRequest $request, int $paymentMethodId, int $locationId, ?CarbonInterface $date = null, ?int $cashSessionId = null): EmployeeAdvance
    {
        return DB::transaction(function () use ($request, $paymentMethodId, $locationId, $date, $cashSessionId) {
            $request = AdvanceRequest::query()->lockForUpdate()->findOrFail($request->id);
            $this->assertStatus($request, AdvanceRequestStatus::Approved, 'payroll.errors.request_not_approved');

            $advance = $this->give->handle([
                'contact_id' => $request->contact_id,
                'location_id' => $locationId,
                'amount' => $request->payableAmount(),
                'installment_amount' => $request->payableInstallment(),
                'payment_method_id' => $paymentMethodId,
                'advance_date' => $date ?? now(),
                'cash_session_id' => $cashSessionId,
                'note' => $request->reason,
            ]);
            $request->update(['status' => AdvanceRequestStatus::Paid, 'employee_advance_id' => $advance->id]);
            rescue(fn () => $this->notifier->paid($request));

            return $advance;
        });
    }

    private function assertAmounts(string|int|float $amount, string|int|float|null $installment): void
    {
        $amount = Decimal::of($amount);
        if (! $amount->isPositive()) {
            throw new DomainException(__('finance.errors.non_positive_amount'));
        }
        if (filled($installment) && (! Decimal::of($installment)->isPositive() || Decimal::of($installment)->isGreaterThan($amount))) {
            throw new DomainException(__('payroll.errors.installment_too_big'));
        }
    }

    private function assertStatus(AdvanceRequest $request, AdvanceRequestStatus $status, string $error): void
    {
        if ($request->status !== $status) {
            throw new DomainException(__($error));
        }
    }
}
