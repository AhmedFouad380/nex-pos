<?php

namespace App\Domain\Purchasing\Actions;

use App\Domain\Catalog\Models\TaxRate;
use App\Domain\Catalog\Models\Unit;
use App\Domain\Catalog\Models\Variation;
use App\Domain\Catalog\Services\UnitConverter;
use App\Domain\CRM\Models\Contact;
use App\Domain\CRM\Services\ContactLedger;
use App\Domain\Finance\Services\Posting;
use App\Domain\Foundation\Services\DocumentNumberGenerator;
use App\Domain\Inventory\Enums\StockMovementType;
use App\Domain\Inventory\Services\StockLedger;
use App\Domain\Purchasing\Enums\PurchaseStatus;
use App\Domain\Purchasing\Models\Purchase;
use App\Domain\Purchasing\Models\PurchaseItem;
use App\Domain\Purchasing\Models\PurchaseReturn;
use App\Domain\Shared\Data\LineInput;
use App\Domain\Shared\Enums\DocumentType;
use App\Domain\Shared\Services\DocumentCalculator;
use App\Domain\Shared\Services\PaymentStatusRefresher;
use App\Support\Decimal;
use App\Support\DomainException;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Goods back to the supplier. Against a purchase: quantities are capped by what was bought minus what was
 * already returned, priced at the purchase cost, and taken from that purchase's own stock layer.
 * Standalone: lines carry their own price and leave stock FIFO.
 *
 * $lines: list of ['purchase_item_id'?, 'variation_id'?, 'unit_id'?, 'qty', 'unit_cost'?, 'tax_rate_id'?]
 */
final class CreatePurchaseReturn
{
    public function __construct(
        private readonly DocumentCalculator $calculator,
        private readonly UnitConverter $units,
        private readonly StockLedger $stock,
        private readonly DocumentNumberGenerator $numbers,
        private readonly PaymentStatusRefresher $statuses,
        private readonly ContactLedger $ledger,
    ) {}

    public function handle(array $lines, ?Purchase $purchase = null, ?int $supplierId = null, ?int $locationId = null,
        ?CarbonInterface $date = null, ?string $note = null): PurchaseReturn
    {
        $lines = array_values(array_filter($lines, fn ($l) => (float) ($l['qty'] ?? 0) > 0));
        if ($lines === []) {
            throw new DomainException(__('inventory.errors.no_items'));
        }
        if ($purchase?->voided_at !== null) {
            throw new DomainException(__('inventory.errors.already_voided'));
        }
        $supplierId = $purchase->supplier_id ?? $supplierId;
        $locationId = $purchase->location_id ?? $locationId;

        return DB::transaction(function () use ($lines, $purchase, $supplierId, $locationId, $date, $note) {
            $rates = TaxRate::query()->pluck('rate', 'id');
            $prepared = [];
            foreach ($lines as $line) {
                if ($purchase) {
                    /** @var PurchaseItem $item */
                    $item = $purchase->items()->with('variation.product')->lockForUpdate()->findOrFail($line['purchase_item_id']);
                    $qty = Decimal::of($line['qty']);
                    $remaining = Decimal::of($item->qty)->minus(Decimal::of($item->qty_returned));
                    if ($qty->isGreaterThan($remaining)) {
                        throw new DomainException(__('purchasing.errors.return_exceeds', ['item' => $item->variation->fullName(), 'max' => Decimal::toDb($remaining, 2)]));
                    }
                    $prepared[] = ['item' => $item, 'variation' => $item->variation, 'unit_id' => $item->unit_id,
                        'multiplier' => Decimal::of($item->unit_multiplier), 'qty' => $qty,
                        'price' => (string) $item->unit_cost, 'tax_rate_id' => $item->tax_rate_id];
                } else {
                    $variation = Variation::query()->with('product')->findOrFail($line['variation_id']);
                    $prepared[] = ['item' => null, 'variation' => $variation, 'unit_id' => (int) $line['unit_id'],
                        'multiplier' => $this->units->multiplier($variation->product, Unit::query()->findOrFail($line['unit_id'])),
                        'qty' => Decimal::of($line['qty']), 'price' => (string) ($line['unit_cost'] ?? 0),
                        'tax_rate_id' => filled($line['tax_rate_id'] ?? null) ? (int) $line['tax_rate_id'] : null];
                }
            }

            $totals = $this->calculator->calculate(array_map(
                fn ($p) => new LineInput((string) $p['qty'], $p['price'], taxRate: (string) ($rates[$p['tax_rate_id']] ?? 0)), $prepared));

            $return = PurchaseReturn::query()->create([
                'number' => $this->numbers->next(DocumentType::PurchaseReturn, $locationId),
                'purchase_id' => $purchase?->id,
                'supplier_id' => $supplierId,
                'location_id' => $locationId,
                'return_date' => $date ?? now(),
                'subtotal' => Decimal::toDb($totals->subtotal),
                'tax_amount' => Decimal::toDb($totals->taxAmount),
                'total' => Decimal::toDb($totals->total),
                'due_total' => Decimal::toDb($totals->total),
                'note' => $note,
                'created_by' => auth()->id(),
            ]);

            foreach ($prepared as $i => $p) {
                $baseQty = $p['qty']->multipliedBy($p['multiplier']);
                $returnItem = $return->items()->create([
                    'purchase_item_id' => $p['item']?->id,
                    'variation_id' => $p['variation']->id,
                    'unit_id' => $p['unit_id'],
                    'unit_multiplier' => Decimal::toDb($p['multiplier']),
                    'qty' => Decimal::toDb($p['qty']),
                    'base_qty' => Decimal::toDb($baseQty),
                    'unit_cost' => Decimal::toDb($totals->lines[$i]->unitPrice),
                    'tax_amount' => Decimal::toDb($totals->lines[$i]->taxPerUnit),
                    'line_total' => Decimal::toDb($totals->lines[$i]->lineTotal),
                    'lot_id' => $p['item']?->lot_id,
                ]);

                if ($p['variation']->product->tracksStock() && ($purchase === null || $purchase->status === PurchaseStatus::Received)) {
                    $this->stock->issue($p['variation'], $locationId, $baseQty, StockMovementType::PurchaseReturn, $returnItem,
                        $p['item']?->lot_id, $date, allowNegative: false, fromSource: $p['item']);
                }
                $p['item']?->update(['qty_returned' => Decimal::toDb(Decimal::of($p['item']->qty_returned)->plus($p['qty']))]);
            }

            $this->statuses->refresh($return);
            $this->ledger->refresh(Contact::query()->findOrFail($supplierId));

            app(Posting::class)->sync($return);

            return $return->refresh();
        });
    }
}
