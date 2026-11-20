<?php

namespace App\Domain\Finance\Actions;

use App\Domain\Finance\Models\Expense;
use App\Domain\Finance\Services\Posting;
use App\Support\DomainException;
use Illuminate\Support\Facades\DB;

/** Cancels an expense and the vouchers that paid only it. */
final class VoidExpense
{
    public function __construct(private readonly VoidPayment $voidPayment) {}

    public function handle(Expense $expense, string $reason): Expense
    {
        if ($expense->voided_at !== null) {
            throw new DomainException(__('finance.errors.already_voided'));
        }

        return DB::transaction(function () use ($expense, $reason) {
            foreach ($expense->allocations()->with('payment.allocations')->get() as $allocation) {
                if ($allocation->payment->voided_at !== null) {
                    continue;
                }
                if ($allocation->payment->allocations->count() > 1) {
                    throw new DomainException(__('sales.errors.shared_payment'));
                }
                $this->voidPayment->handle($allocation->payment, $reason);
            }
            $expense->update(['voided_at' => now(), 'void_reason' => $reason]);
            app(Posting::class)->sync($expense);

            return $expense;
        });
    }
}
