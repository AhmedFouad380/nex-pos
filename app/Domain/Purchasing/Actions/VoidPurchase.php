<?php

namespace App\Domain\Purchasing\Actions;

use App\Domain\CRM\Services\ContactLedger;
use App\Domain\Finance\Services\Posting;
use App\Domain\Inventory\Services\StockLedger;
use App\Domain\Purchasing\Models\Purchase;
use App\Domain\Purchasing\Services\LastPurchasePrice;
use App\Domain\Purchasing\Services\PurchaseOrderProgress;
use App\Support\Decimal;
use App\Support\DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Cancels a purchase: stock goes back out (refused if some was already sold — use a return),
 * payments must be voided first, and returns against it must be voided first.
 */
final class VoidPurchase
{
    public function __construct(
        private readonly StockLedger $ledger,
        private readonly ContactLedger $contacts,
        private readonly PurchaseOrderProgress $orders,
        private readonly LastPurchasePrice $lastPrices,
    ) {}

    public function handle(Purchase $purchase, string $reason): Purchase
    {
        if ($purchase->voided_at !== null) {
            throw new DomainException(__('inventory.errors.already_voided'));
        }
        if (Decimal::of($purchase->paid_total)->isPositive()) {
            throw new DomainException(__('finance.errors.has_payments', ['amount' => Decimal::toDb($purchase->paid_total, 2)]));
        }
        if ($purchase->returns()->whereNull('voided_at')->exists()) {
            throw new DomainException(__('purchasing.errors.has_returns'));
        }

        return DB::transaction(function () use ($purchase, $reason) {
            foreach ($purchase->items as $item) {
                $this->ledger->reverse($item);
            }
            $purchase->update(['voided_at' => now(), 'void_reason' => $reason, 'voided_by' => auth()->id()]);

            if ($purchase->purchase_order_id) {
                $this->orders->refresh($purchase->purchase_order_id);
            }
            $this->contacts->refresh($purchase->supplier);
            // the product's purchase price goes back to its previous purchase
            $this->lastPrices->refresh($purchase->items->pluck('variation_id')->unique()->values()->all());

            app(Posting::class)->sync($purchase);

            return $purchase;
        });
    }
}
