<?php

namespace App\Domain\Purchasing\Actions;

use App\Domain\Catalog\Actions\SaveProduct;
use App\Domain\Catalog\Enums\TaxType;
use App\Domain\Catalog\Models\TaxRate;
use App\Domain\Catalog\Models\Unit;
use App\Domain\Catalog\Models\Variation;
use App\Domain\Catalog\Services\UnitConverter;
use App\Domain\CRM\Enums\ContactType;
use App\Domain\CRM\Models\Contact;
use App\Domain\CRM\Services\ContactLedger;
use App\Domain\Finance\Services\Posting;
use App\Domain\Foundation\Services\DocumentNumberGenerator;
use App\Domain\Inventory\Models\Lot;
use App\Domain\Purchasing\Data\PurchaseData;
use App\Domain\Purchasing\Enums\CostDistribution;
use App\Domain\Purchasing\Enums\PurchaseStatus;
use App\Domain\Purchasing\Models\Purchase;
use App\Domain\Purchasing\Services\LandedCostAllocator;
use App\Domain\Purchasing\Services\PurchaseOrderProgress;
use App\Domain\Shared\Data\LineInput;
use App\Domain\Shared\Enums\DocumentType;
use App\Domain\Shared\Enums\DurationUnit;
use App\Domain\Shared\Services\DocumentCalculator;
use App\Domain\Shared\Services\PaymentStatusRefresher;
use App\Support\Decimal;
use App\Support\DomainException;
use Brick\Math\BigDecimal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Purchase invoice: totals, landed cost per line, and — when received — stock in at landed cost.
 * Optionally updates selling prices and the progress of the purchase order it came from.
 */
final class CreatePurchase
{
    public function __construct(
        private readonly DocumentCalculator $calculator,
        private readonly UnitConverter $units,
        private readonly LandedCostAllocator $landed,
        private readonly DocumentNumberGenerator $numbers,
        private readonly ReceivePurchase $receive,
        private readonly PaymentStatusRefresher $statuses,
        private readonly ContactLedger $ledger,
        private readonly SaveProduct $products,
        private readonly PurchaseOrderProgress $orders,
    ) {}

    public function handle(PurchaseData $data): Purchase
    {
        if ($data->lines === []) {
            throw new DomainException(__('inventory.errors.no_items'));
        }
        $supplier = Contact::query()->findOrFail($data->supplierId);
        if (in_array($supplier->type, [ContactType::Customer, ContactType::Employee], true)) {
            throw new DomainException(__('purchasing.errors.not_a_supplier', ['name' => $supplier->name]));
        }

        return DB::transaction(function () use ($data, $supplier) {
            $rates = TaxRate::query()->pluck('rate', 'id');
            $variations = Variation::query()->with('product')->findMany(array_map(fn ($l) => $l->variationId, $data->lines))->keyBy('id');

            $totals = $this->calculator->calculate(
                array_map(fn ($l) => new LineInput($l->qty, $l->unitCost, $l->discountType, $l->discountValue, (string) ($rates[$l->taxRateId] ?? 0)), $data->lines),
                $data->discountType, $data->discountValue, $rates[$data->taxRateId] ?? 0,
                charges: Decimal::of($data->shippingCharges)->plus($this->costsTotal($data->costs)),
            );

            $date = $data->date ?? now();
            $purchase = Purchase::query()->create([
                'number' => $this->numbers->next(DocumentType::Purchase, $data->locationId),
                'supplier_ref' => $data->supplierRef,
                'supplier_id' => $supplier->id,
                'location_id' => $data->locationId,
                'purchase_order_id' => $data->purchaseOrderId,
                'purchase_date' => $date,
                'due_date' => $data->dueDate ?? $this->dueDate($supplier, $date),
                'status' => PurchaseStatus::Pending, // ReceivePurchase flips it once stock is in
                'currency_id' => $data->currencyId,
                'exchange_rate' => Decimal::toDb($data->exchangeRate, 6),
                'subtotal' => Decimal::toDb($totals->subtotal),
                'discount_type' => $data->discountType,
                'discount_value' => Decimal::toDb($data->discountValue),
                'discount_amount' => Decimal::toDb($totals->discountAmount),
                'tax_rate_id' => $data->taxRateId,
                'tax_amount' => Decimal::toDb($totals->taxAmount),
                'shipping_charges' => Decimal::toDb($data->shippingCharges),
                'shipping_details' => $data->shippingDetails,
                'additional_costs_total' => Decimal::toDb($this->costsTotal($data->costs)),
                'total' => Decimal::toDb($totals->total),
                'due_total' => Decimal::toDb($totals->total),
                'note' => $data->note,
                'created_by' => auth()->id(),
            ]);

            foreach ($data->costs as $cost) {
                if (Decimal::of($cost['amount'])->isPositive()) {
                    $purchase->costs()->create([
                        'name' => $cost['name'], 'amount' => Decimal::toDb($cost['amount']),
                        'distribute_by' => $cost['distribute_by'] ?? CostDistribution::Value,
                    ]);
                }
            }

            // landed cost: line value − its share of the invoice discount + its share of shipping / costs
            $multipliers = [];
            $landedInput = [];
            foreach ($data->lines as $i => $line) {
                $variation = $variations[$line->variationId] ?? throw new DomainException(__('inventory.errors.no_items'));
                $multipliers[$i] = $this->units->multiplier($variation->product, Unit::query()->findOrFail($line->unitId));
                $baseQty = Decimal::of($line->qty)->multipliedBy($multipliers[$i]);
                if (! $baseQty->isPositive()) {
                    throw new DomainException(__('inventory.errors.non_positive_qty'));
                }
                $landedInput[$i] = [
                    'net' => $totals->lines[$i]->lineNet,
                    'base_qty' => $baseQty,
                    'weight' => Decimal::of($variation->product->weight)->multipliedBy($baseQty),
                ];
            }
            $additions = [['amount' => Decimal::of($data->shippingCharges), 'by' => CostDistribution::Value]];
            foreach ($data->costs as $cost) {
                $by = $cost['distribute_by'] ?? CostDistribution::Value;
                $additions[] = ['amount' => Decimal::of($cost['amount']), 'by' => $by instanceof CostDistribution ? $by : CostDistribution::from($by)];
            }
            $landedCosts = $this->landed->allocate($landedInput, $totals->discountAmount, $additions, Decimal::of($data->exchangeRate));

            foreach ($data->lines as $i => $line) {
                $result = $totals->lines[$i];
                $variation = $variations[$line->variationId];
                $lotId = $line->lotNumber
                    ? Lot::query()->firstOrCreate(['variation_id' => $variation->id, 'number' => $line->lotNumber],
                        ['mfg_date' => $line->mfgDate, 'exp_date' => $line->expDate])->id
                    : null;

                $purchase->items()->create([
                    'purchase_order_item_id' => $line->purchaseOrderItemId,
                    'variation_id' => $variation->id,
                    'unit_id' => $line->unitId,
                    'unit_multiplier' => Decimal::toDb($multipliers[$i]),
                    'qty' => Decimal::toDb($line->qty),
                    'base_qty' => Decimal::toDb($landedInput[$i]['base_qty']),
                    'unit_cost_before_discount' => Decimal::toDb($line->unitCost),
                    'discount_type' => $line->discountType,
                    'discount_value' => Decimal::toDb($line->discountValue),
                    'unit_cost' => Decimal::toDb($result->unitPrice),
                    'tax_rate_id' => $line->taxRateId,
                    'tax_amount' => Decimal::toDb($result->taxPerUnit),
                    'unit_cost_inc_tax' => Decimal::toDb($result->unitPriceIncTax),
                    'landed_unit_cost' => Decimal::toDb($landedCosts[$i]),
                    'line_total' => Decimal::toDb($result->lineTotal),
                    'lot_number' => $line->lotNumber,
                    'lot_id' => $lotId,
                    'mfg_date' => $line->mfgDate,
                    'exp_date' => $line->expDate,
                    'new_sell_price' => $line->newSellPrice !== null ? Decimal::toDb($line->newSellPrice) : null,
                ]);

                $this->updatePrices($variation, $landedCosts[$i], $line->newSellPrice, $multipliers[$i]);
            }

            if ($data->status === PurchaseStatus::Received) {
                $this->receive->handle($purchase);
            }
            if ($data->purchaseOrderId) {
                $this->orders->refresh($data->purchaseOrderId);
            }

            $this->statuses->refresh($purchase);
            $this->ledger->refresh($supplier);

            app(Posting::class)->sync($purchase);

            return $purchase->refresh();
        });
    }

    /** Remembers the latest landed cost as the default purchase price; applies a new selling price if given. */
    private function updatePrices(Variation $variation, BigDecimal $landedBaseCost, ?string $newSellPrice, BigDecimal $multiplier): void
    {
        $product = $variation->product;
        $sell = $newSellPrice !== null
            ? Decimal::divide($newSellPrice, $multiplier) // entered per chosen unit → per base unit
            : Decimal::of($product->tax_type === TaxType::Inclusive ? $variation->sell_price_inc_tax : $variation->sell_price);
        $taxRate = $product->tax_rate_id ? (string) TaxRate::query()->whereKey($product->tax_rate_id)->value('rate') : '0';

        $variation->update($this->products->prices($product, (string) $landedBaseCost, (string) $sell, Decimal::of($taxRate)));
    }

    private function dueDate(Contact $supplier, $date): ?string
    {
        if (! $supplier->pay_term_number) {
            return null;
        }
        $date = Carbon::parse($date);

        return ($supplier->pay_term_unit === DurationUnit::Month
            ? $date->copy()->addMonths($supplier->pay_term_number)
            : $date->copy()->addDays($supplier->pay_term_number))->toDateString();
    }

    private function costsTotal(array $costs): BigDecimal
    {
        return array_reduce($costs, fn (BigDecimal $c, array $cost) => $c->plus(Decimal::of($cost['amount'] ?? 0)), BigDecimal::zero());
    }
}
