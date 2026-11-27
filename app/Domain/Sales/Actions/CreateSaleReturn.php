<?php

namespace App\Domain\Sales\Actions;

use App\Domain\CRM\Services\ContactLedger;
use App\Domain\Finance\Actions\RecordPayment;
use App\Domain\Finance\Data\PaymentData;
use App\Domain\Finance\Enums\PaymentDirection;
use App\Domain\Finance\Services\Posting;
use App\Domain\Foundation\Services\DocumentNumberGenerator;
use App\Domain\Inventory\Enums\StockMovementType;
use App\Domain\Inventory\Services\StockLedger;
use App\Domain\Sales\Enums\SaleStatus;
use App\Domain\Sales\Models\Sale;
use App\Domain\Sales\Models\SaleItem;
use App\Domain\Sales\Models\SaleReturn;
use App\Domain\Shared\Enums\DocumentType;
use App\Domain\Shared\Services\PaymentStatusRefresher;
use App\Support\Decimal;
use App\Support\DomainException;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Customer brings goods back. Priced exactly as sold (after line and a proportional share of the
 * invoice discount); restocked items come back at the cost they left with. Optionally refunds now.
 *
 * $lines: list of ['sale_item_id' => int, 'qty' => numeric, 'restock' => bool (default true)]
 */
final class CreateSaleReturn
{
    public function __construct(
        private readonly StockLedger $stock,
        private readonly DocumentNumberGenerator $numbers,
        private readonly PaymentStatusRefresher $statuses,
        private readonly ContactLedger $ledger,
        private readonly RecordPayment $payments,
    ) {}

    public function handle(Sale $sale, array $lines, ?int $refundMethodId = null, ?int $cashSessionId = null,
        ?CarbonInterface $date = null, ?string $reason = null): SaleReturn
    {
        if ($sale->status !== SaleStatus::Final || $sale->voided_at !== null) {
            throw new DomainException(__('sales.errors.not_final'));
        }
        $lines = array_values(array_filter($lines, fn ($l) => (float) ($l['qty'] ?? 0) > 0));
        if ($lines === []) {
            throw new DomainException(__('inventory.errors.no_items'));
        }

        return DB::transaction(function () use ($sale, $lines, $refundMethodId, $cashSessionId, $date, $reason) {
            // share of the invoice-level discount each line carries (so returns never refund more than was paid)
            $subtotal = Decimal::of($sale->subtotal);
            $invoiceFactor = $subtotal->isPositive()
                ? $subtotal->minus(Decimal::of($sale->discount_amount))->dividedBy($subtotal, 8, RoundingMode::HalfUp)
                : BigDecimal::one();

            $return = SaleReturn::query()->create([
                'number' => $this->numbers->next(DocumentType::SaleReturn, $sale->location_id),
                'sale_id' => $sale->id,
                'customer_id' => $sale->customer_id,
                'location_id' => $sale->location_id,
                'cash_session_id' => $cashSessionId,
                'return_date' => $date ?? now(),
                'reason' => $reason,
                'created_by' => auth()->id(),
            ]);

            $total = BigDecimal::zero();
            $tax = BigDecimal::zero();
            $cost = BigDecimal::zero();
            foreach ($lines as $line) {
                /** @var SaleItem $item */
                $item = $sale->items()->whereNull('parent_item_id')->with(['variation.product', 'children.variation.product'])
                    ->lockForUpdate()->findOrFail($line['sale_item_id']);
                $qty = Decimal::of($line['qty']);
                $remaining = Decimal::of($item->qty)->minus(Decimal::of($item->qty_returned));
                if ($qty->isGreaterThan($remaining)) {
                    throw new DomainException(__('sales.errors.return_exceeds', ['item' => $item->variation->fullName(), 'max' => Decimal::toDb($remaining, 2)]));
                }

                $unitPrice = Decimal::of($item->unit_price_inc_tax)->multipliedBy($invoiceFactor);
                $lineTotal = $qty->multipliedBy($unitPrice)->toScale(2, RoundingMode::HalfUp);
                $restock = (bool) ($line['restock'] ?? true);
                $unitCost = Decimal::divide($item->cost_total, $item->qty);

                $returnItem = $return->items()->create([
                    'sale_item_id' => $item->id,
                    'variation_id' => $item->variation_id,
                    'unit_id' => $item->unit_id,
                    'unit_multiplier' => $item->unit_multiplier,
                    'qty' => Decimal::toDb($qty),
                    'base_qty' => Decimal::toDb($qty->multipliedBy(Decimal::of($item->unit_multiplier))),
                    'unit_price' => Decimal::toDb($unitPrice),
                    'tax_amount' => Decimal::toDb(Decimal::of($item->tax_amount)->multipliedBy($invoiceFactor)),
                    'line_total' => Decimal::toDb($lineTotal),
                    'unit_cost' => Decimal::toDb($unitCost),
                    'restock' => $restock,
                    'lot_id' => $item->lot_id,
                ]);

                if ($restock) {
                    $this->restock($sale, $item, $returnItem, $qty);
                }
                $item->update(['qty_returned' => Decimal::toDb(Decimal::of($item->qty_returned)->plus($qty))]);

                $total = $total->plus($lineTotal);
                $tax = $tax->plus($qty->multipliedBy(Decimal::of($item->tax_amount))->multipliedBy($invoiceFactor));
                $cost = $cost->plus($qty->multipliedBy($unitCost));
            }

            $return->update([
                'subtotal' => Decimal::toDb($total), 'tax_amount' => Decimal::toDb($tax), 'total' => Decimal::toDb($total),
                'due_total' => Decimal::toDb($total), 'cost_total' => Decimal::toDb($cost),
            ]);

            if ($refundMethodId) {
                $refund = $this->payments->handle(new PaymentData(
                    direction: PaymentDirection::Out, paymentMethodId: $refundMethodId, amount: (string) $total,
                    contactId: $sale->customer_id, locationId: $sale->location_id, paidAt: $return->return_date,
                    allocations: [[$return, (string) $total]], cashSessionId: $cashSessionId,
                ));
                if ($return->cash_session_id === null && $refund->cash_session_id !== null) {
                    $return->update(['cash_session_id' => $refund->cash_session_id]); // refunded from the box of an open shift
                }
            }

            $this->statuses->refresh($return);
            $this->ledger->refresh($sale->customer);

            app(Posting::class)->sync($return);

            return $return->refresh();
        });
    }

    /** Puts the returned quantity back at the cost it left with (combo: each component). */
    private function restock(Sale $sale, SaleItem $item, $returnItem, BigDecimal $qty): void
    {
        $targets = $item->children->isNotEmpty() ? $item->children : collect([$item]);
        foreach ($targets as $target) {
            if (! $target->variation->product->tracksStock()) {
                continue;
            }
            $share = $item->children->isNotEmpty() ? Decimal::divide($target->base_qty, $item->qty) : Decimal::of($item->unit_multiplier);
            $unitCost = Decimal::of($target->base_qty)->isPositive() ? Decimal::divide($target->cost_total, $target->base_qty) : BigDecimal::zero();
            $this->stock->receive($target->variation, $sale->location_id, $qty->multipliedBy($share), $unitCost,
                StockMovementType::SaleReturn, $returnItem, $target->lot_id);
        }
    }
}
