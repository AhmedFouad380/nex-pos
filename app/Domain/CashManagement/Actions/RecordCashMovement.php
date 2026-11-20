<?php

namespace App\Domain\CashManagement\Actions;

use App\Domain\CashManagement\Enums\CashSessionStatus;
use App\Domain\CashManagement\Models\CashMovement;
use App\Domain\CashManagement\Models\CashSession;
use App\Domain\CashManagement\Services\CashSessionSummary;
use App\Domain\Finance\Enums\PaymentDirection;
use App\Support\Decimal;
use App\Support\DomainException;

/** Manual cash in (float top-up) or cash out (paid to the bank, small expenses) during a shift. */
final class RecordCashMovement
{
    public function __construct(private readonly CashSessionSummary $summary) {}

    public function handle(CashSession $session, PaymentDirection $direction, string|int|float $amount, ?string $reason = null): CashMovement
    {
        if ($session->status !== CashSessionStatus::Open) {
            throw new DomainException(__('cash.errors.session_closed'));
        }
        $amount = Decimal::of($amount);
        if (! $amount->isPositive()) {
            throw new DomainException(__('finance.errors.non_positive_amount'));
        }
        if ($direction === PaymentDirection::Out && $amount->isGreaterThan($this->summary->expectedCash($session))) {
            throw new DomainException(__('cash.errors.not_enough_cash'));
        }

        return $session->movements()->create([
            'direction' => $direction,
            'amount' => Decimal::toDb($amount),
            'reason' => $reason,
            'created_by' => auth()->id(),
        ]);
    }
}
