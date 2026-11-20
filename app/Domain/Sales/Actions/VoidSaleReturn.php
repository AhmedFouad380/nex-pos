<?php

namespace App\Domain\Sales\Actions;

use App\Domain\CRM\Services\ContactLedger;
use App\Domain\Finance\Actions\VoidPayment;
use App\Domain\Finance\Services\Posting;
use App\Domain\Inventory\Services\StockLedger;
use App\Domain\Sales\Models\SaleReturn;
use App\Support\Decimal;
use App\Support\DomainException;
use Illuminate\Support\Facades\DB;

final class VoidSaleReturn
{
    public function __construct(private readonly StockLedger $stock, private readonly VoidPayment $voidPayment, private readonly ContactLedger $ledger) {}

    public function handle(SaleReturn $return, string $reason): SaleReturn
    {
        if ($return->voided_at !== null) {
            throw new DomainException(__('inventory.errors.already_voided'));
        }

        return DB::transaction(function () use ($return, $reason) {
            foreach ($return->allocations()->with('payment.allocations')->get() as $allocation) {
                if ($allocation->payment->voided_at === null) {
                    if ($allocation->payment->allocations->count() > 1) {
                        throw new DomainException(__('sales.errors.shared_payment'));
                    }
                    $this->voidPayment->handle($allocation->payment, $reason);
                }
            }
            foreach ($return->items()->with('saleItem')->get() as $item) {
                $this->stock->reverse($item);
                $item->saleItem?->update(['qty_returned' => Decimal::toDb(Decimal::of($item->saleItem->qty_returned)->minus(Decimal::of($item->qty)))]);
            }
            $return->update(['voided_at' => now(), 'void_reason' => $reason]);
            $this->ledger->refresh($return->customer);

            app(Posting::class)->sync($return);

            return $return;
        });
    }
}
