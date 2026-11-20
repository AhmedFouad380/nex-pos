<?php

namespace App\Domain\Sales\Actions;

use App\Domain\CRM\Services\ContactLedger;
use App\Domain\Finance\Actions\VoidPayment;
use App\Domain\Finance\Services\Posting;
use App\Domain\Inventory\Services\StockLedger;
use App\Domain\Sales\Enums\SaleStatus;
use App\Domain\Sales\Models\Sale;
use App\Support\DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Cancels a final invoice: stock goes back, and the payments that only settled this invoice are
 * voided with it (the cash leaves the drawer total). Refused when the invoice has live returns.
 */
final class VoidSale
{
    public function __construct(
        private readonly StockLedger $stock,
        private readonly VoidPayment $voidPayment,
        private readonly ContactLedger $ledger,
    ) {}

    public function handle(Sale $sale, string $reason): Sale
    {
        if ($sale->voided_at !== null) {
            throw new DomainException(__('inventory.errors.already_voided'));
        }
        if ($sale->status !== SaleStatus::Final) {
            throw new DomainException(__('sales.errors.not_final'));
        }
        if ($sale->returns()->whereNull('voided_at')->exists()) {
            throw new DomainException(__('sales.errors.has_returns'));
        }

        return DB::transaction(function () use ($sale, $reason) {
            foreach ($sale->allocations()->with('payment.allocations')->get() as $allocation) {
                $payment = $allocation->payment;
                if ($payment->voided_at !== null) {
                    continue;
                }
                if ($payment->allocations->count() > 1) {
                    throw new DomainException(__('sales.errors.shared_payment'));
                }
                $this->voidPayment->handle($payment, $reason);
            }

            foreach ($sale->items as $item) {
                $this->stock->reverse($item);
            }
            $sale->update(['voided_at' => now(), 'void_reason' => $reason, 'voided_by' => auth()->id()]);
            $this->ledger->refresh($sale->customer);

            app(Posting::class)->sync($sale);

            return $sale;
        });
    }
}
