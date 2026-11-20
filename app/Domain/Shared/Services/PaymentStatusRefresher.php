<?php

namespace App\Domain\Shared\Services;

use App\Domain\CRM\Models\ContactOpeningBalance;
use App\Domain\Finance\Models\Expense;
use App\Domain\Finance\Models\PaymentAllocation;
use App\Domain\Purchasing\Models\Purchase;
use App\Domain\Purchasing\Models\PurchaseReturn;
use App\Domain\Sales\Models\Sale;
use App\Domain\Sales\Models\SaleReturn;
use App\Domain\Shared\Enums\PaymentStatus;
use App\Support\Decimal;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/** Recomputes paid / due / payment_status of a payable document from its live (non-voided) allocations. */
final class PaymentStatusRefresher
{
    /** class => [total column, paid column] */
    public const COLUMNS = [
        Purchase::class => ['total', 'paid_total'],
        PurchaseReturn::class => ['total', 'paid_total'],
        Sale::class => ['total', 'paid_total'],
        SaleReturn::class => ['total', 'refunded_total'],
        Expense::class => ['total', 'paid_total'],
        ContactOpeningBalance::class => ['amount', 'paid_total'],
    ];

    public function refresh(Model $document): void
    {
        [$totalColumn, $paidColumn] = self::COLUMNS[$document::class]
            ?? throw new InvalidArgumentException($document::class.' is not a payable document.');

        $paid = Decimal::of(self::allocatedTo($document));
        $total = Decimal::of($document->getAttribute($totalColumn));
        $due = $total->minus($paid);

        $document->forceFill([
            $paidColumn => Decimal::toDb($paid),
            'due_total' => Decimal::toDb($due),
            'payment_status' => match (true) {
                ! $due->isPositive() => PaymentStatus::Paid,
                $paid->isPositive() => PaymentStatus::Partial,
                default => PaymentStatus::Due,
            },
        ])->save();
    }

    public static function allocatedTo(Model $document): string
    {
        return (string) PaymentAllocation::query()
            ->where('allocatable_type', $document->getMorphClass())
            ->where('allocatable_id', $document->getKey())
            ->whereHas('payment', fn ($q) => $q->whereNull('voided_at'))
            ->sum('amount');
    }
}
