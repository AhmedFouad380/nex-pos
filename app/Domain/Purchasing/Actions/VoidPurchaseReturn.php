<?php

namespace App\Domain\Purchasing\Actions;

use App\Domain\CRM\Services\ContactLedger;
use App\Domain\Finance\Services\Posting;
use App\Domain\Inventory\Services\StockLedger;
use App\Domain\Purchasing\Models\PurchaseReturn;
use App\Support\Decimal;
use App\Support\DomainException;
use Illuminate\Support\Facades\DB;

final class VoidPurchaseReturn
{
    public function __construct(private readonly StockLedger $stock, private readonly ContactLedger $ledger) {}

    public function handle(PurchaseReturn $return, string $reason): PurchaseReturn
    {
        if ($return->voided_at !== null) {
            throw new DomainException(__('inventory.errors.already_voided'));
        }
        if (Decimal::of($return->paid_total)->isPositive()) {
            throw new DomainException(__('finance.errors.has_payments', ['amount' => Decimal::toDb($return->paid_total, 2)]));
        }

        return DB::transaction(function () use ($return, $reason) {
            foreach ($return->items()->with('purchaseItem')->get() as $item) {
                $this->stock->reverse($item);
                $item->purchaseItem?->update(['qty_returned' => Decimal::toDb(Decimal::of($item->purchaseItem->qty_returned)->minus(Decimal::of($item->qty)))]);
            }
            $return->update(['voided_at' => now(), 'void_reason' => $reason]);
            $this->ledger->refresh($return->supplier);

            app(Posting::class)->sync($return);

            return $return;
        });
    }
}
