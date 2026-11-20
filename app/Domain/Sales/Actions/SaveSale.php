<?php

namespace App\Domain\Sales\Actions;

use App\Domain\CashManagement\Enums\CashSessionStatus;
use App\Domain\CashManagement\Models\CashSession;
use App\Domain\Catalog\Enums\ProductType;
use App\Domain\Catalog\Models\TaxRate;
use App\Domain\Catalog\Models\Unit;
use App\Domain\Catalog\Models\Variation;
use App\Domain\Catalog\Services\PriceResolver;
use App\Domain\Catalog\Services\UnitConverter;
use App\Domain\CRM\Enums\ContactType;
use App\Domain\CRM\Models\Contact;
use App\Domain\CRM\Services\ContactLedger;
use App\Domain\Finance\Actions\RecordPayment;
use App\Domain\Finance\Data\PaymentData;
use App\Domain\Finance\Enums\PaymentDirection;
use App\Domain\Finance\Enums\PaymentMethodType;
use App\Domain\Finance\Models\PaymentMethod;
use App\Domain\Finance\Services\Posting;
use App\Domain\Foundation\Services\DocumentNumberGenerator;
use App\Domain\Inventory\Enums\StockMovementType;
use App\Domain\Inventory\Models\StockLevel;
use App\Domain\Inventory\Services\StockLedger;
use App\Domain\Sales\Data\SaleData;
use App\Domain\Sales\Data\SaleLineData;
use App\Domain\Sales\Enums\SaleChannel;
use App\Domain\Sales\Enums\SaleItemChildType;
use App\Domain\Sales\Enums\SaleStatus;
use App\Domain\Sales\Models\Sale;
use App\Domain\Sales\Models\SaleItem;
use App\Domain\Shared\Data\DocumentTotals;
use App\Domain\Shared\Data\LineInput;
use App\Domain\Shared\Data\LineResult;
use App\Domain\Shared\Enums\DiscountType;
use App\Domain\Shared\Enums\DocumentType;
use App\Domain\Shared\Services\DocumentCalculator;
use App\Domain\Shared\Services\PaymentStatusRefresher;
use App\Models\User;
use App\Settings\PosSettings;
use App\Support\Decimal;
use App\Support\DomainException;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Creates or updates a sale. Drafts, quotations, proformas and suspended sales are only saved
 * (no stock, no money) and can be edited again; a FINAL sale gets an invoice number, takes stock
 * out (combos via their components), records the tenders as payments and is immutable afterwards.
 */
final class SaveSale
{
    public function __construct(
        private readonly DocumentCalculator $calculator,
        private readonly PriceResolver $prices,
        private readonly UnitConverter $units,
        private readonly StockLedger $stock,
        private readonly DocumentNumberGenerator $numbers,
        private readonly RecordPayment $payments,
        private readonly PaymentStatusRefresher $statuses,
        private readonly ContactLedger $ledger,
        private readonly PosSettings $pos,
    ) {}

    public function handle(SaleData $data, ?Sale $sale = null): Sale
    {
        if ($data->lines === []) {
            throw new DomainException(__('inventory.errors.no_items'));
        }
        if ($sale !== null && ($sale->status === SaleStatus::Final || $sale->voided_at !== null)) {
            throw new DomainException(__('sales.errors.final_is_immutable'));
        }

        return DB::transaction(function () use ($data, $sale) {
            $customer = $this->customer($data->customerId);
            $session = $this->session($data);
            $user = auth()->user();

            [$prepared, $totals] = $this->price($data);
            $this->assertDiscountAllowed($user, $prepared, $totals);
            $this->assertNotBelowCost($user, $prepared, $totals, $data->locationId);

            $sale ??= new Sale(['public_token' => (string) Str::ulid(), 'created_by' => auth()->id()]);
            $sale->fill([
                'location_id' => $data->locationId,
                'customer_id' => $customer->id,
                'customer_group_id' => $customer->customer_group_id,
                'cash_session_id' => $session?->id,
                'status' => $data->status === SaleStatus::Final ? SaleStatus::Draft : $data->status, // final is set by finalize()
                'channel' => $data->channel,
                'sale_date' => $data->date ?? now(),
                'valid_until' => $data->validUntil,
                'price_group_id' => $data->priceGroupId,
                'service_type_id' => $data->serviceTypeId,
                'table_id' => $data->tableId,
                'waiter_id' => $data->waiterId,
                'commission_agent_id' => $data->commissionAgentId,
                'subtotal' => Decimal::toDb($totals->subtotal),
                'discount_type' => $data->discountType,
                'discount_value' => Decimal::toDb($data->discountValue),
                'discount_amount' => Decimal::toDb($totals->discountAmount),
                'tax_rate_id' => $data->taxRateId,
                'tax_amount' => Decimal::toDb($totals->taxAmount),
                'shipping_charges' => Decimal::toDb($data->shippingCharges),
                'packing_charge' => Decimal::toDb($data->packingCharge),
                'total' => Decimal::toDb($totals->total),
                'due_total' => Decimal::toDb($totals->total),
                'shipping_address' => $data->shippingAddress,
                'note' => $data->note,
                'staff_note' => $data->staffNote,
                'updated_by' => auth()->id(),
            ]);
            if (! $sale->exists && $data->status !== SaleStatus::Final) {
                $sale->draft_no = $this->numbers->next(
                    $data->status === SaleStatus::Quotation ? DocumentType::Quotation : DocumentType::Draft, $data->locationId);
            }
            $sale->save();

            $sale->items()->whereNotNull('parent_item_id')->delete();
            $sale->items()->delete();
            foreach ($prepared as $i => $p) {
                $this->storeLine($sale, $p, $totals->lines[$i]);
            }

            if ($data->status === SaleStatus::Final) {
                $this->finalize($sale->refresh(), $customer, $data);
            }

            app(Posting::class)->sync($sale);

            return $sale->refresh();
        });
    }

    /** Money change to hand back for the given tenders (only cash can be over-tendered). */
    public static function change(array $tenders, string $total): BigDecimal
    {
        $tendered = array_reduce($tenders, fn (BigDecimal $c, array $t) => $c->plus(Decimal::of($t['amount'] ?? 0)), BigDecimal::zero());

        return BigDecimal::max(BigDecimal::zero(), $tendered->minus(Decimal::of($total)));
    }

    // ---------------------------------------------------------------------------------------------

    private function finalize(Sale $sale, Contact $customer, SaleData $data): void
    {
        $sale->update([
            'status' => SaleStatus::Final,
            'invoice_no' => $sale->invoice_no ?? $this->numbers->next(DocumentType::Sale, $sale->location_id),
            'sale_date' => $data->date ?? now(),
        ]);

        // stock out + COGS
        $costTotal = BigDecimal::zero();
        foreach ($sale->items()->with(['variation.product', 'children.variation.product'])->whereNull('parent_item_id')->get() as $item) {
            $costTotal = $costTotal->plus($this->issue($sale, $item));
        }
        $sale->update(['cost_total' => Decimal::toDb($costTotal)]);

        // payments: cash may be over-tendered (change); other methods may not exceed what's due
        $due = Decimal::of($sale->total);
        $change = self::change($data->tenders, (string) $sale->total);
        foreach ($data->tenders as $tender) {
            $amount = Decimal::of($tender['amount']);
            if (! $amount->isPositive() || ! $due->isPositive()) {
                continue;
            }
            $method = PaymentMethod::query()->findOrFail($tender['payment_method_id']);
            if ($change->isPositive() && $method->type === PaymentMethodType::Cash) {
                $back = BigDecimal::min($change, $amount);
                $amount = $amount->minus($back);
                $change = $change->minus($back);
            }
            $amount = BigDecimal::min($amount, $due);
            if (! $amount->isPositive()) {
                continue;
            }
            $this->payments->handle(new PaymentData(
                direction: PaymentDirection::In, paymentMethodId: $method->id, amount: (string) $amount,
                contactId: $customer->id, locationId: $sale->location_id, paidAt: $sale->sale_date,
                allocations: [[$sale, (string) $amount]], cashSessionId: $sale->cash_session_id, reference: $tender['reference'] ?? null,
            ));
            $due = $due->minus($amount);
        }
        if ($change->isPositive()) {
            throw new DomainException(__('sales.errors.overpaid_non_cash'));
        }

        $this->statuses->refresh($sale);
        $this->assertCreditAllowed($customer, $sale, $data);
        $this->ledger->refresh($customer);
    }

    /** Issues stock for a line (or its combo components) and stores its cost. */
    private function issue(Sale $sale, SaleItem $item): BigDecimal
    {
        $product = $item->variation->product;

        if ($product->type === ProductType::Combo) {
            $cost = BigDecimal::zero();
            foreach ($item->children as $child) {
                $cost = $cost->plus($this->issue($sale, $child));
            }
            $item->update(['cost_total' => Decimal::toDb($cost), 'unit_cost' => Decimal::toDb(Decimal::divide($cost, $item->qty))]);

            return $cost;
        }
        if (! $product->tracksStock()) {
            return BigDecimal::zero();
        }

        $issue = $this->stock->issue($item->variation, $sale->location_id, $item->base_qty, StockMovementType::Sale, $item,
            $item->lot_id, $sale->sale_date);
        $item->update([
            'unit_cost' => Decimal::toDb($issue->unitCost->multipliedBy(Decimal::of($item->unit_multiplier))),
            'cost_total' => Decimal::toDb($issue->totalCost),
        ]);

        return $issue->totalCost;
    }

    /** @return array{0: list<array<string, mixed>>, 1: DocumentTotals} */
    private function price(SaleData $data): array
    {
        $rates = TaxRate::query()->pluck('rate', 'id');
        $variations = Variation::query()->with(['product', 'prices', 'comboItems.componentVariation.product'])
            ->findMany(array_map(fn (SaleLineData $l) => $l->variationId, $data->lines))->keyBy('id');

        $prepared = [];
        $inputs = [];
        foreach ($data->lines as $line) {
            $variation = $variations[$line->variationId] ?? throw new DomainException(__('inventory.errors.no_items'));
            $product = $variation->product;
            if (! $product->is_sellable || ! $product->is_active) {
                throw new DomainException(__('sales.errors.not_sellable', ['item' => $variation->fullName()]));
            }
            $unitId = $line->unitId ?? $product->unit_id;
            $multiplier = $this->units->multiplier($product, Unit::query()->findOrFail($unitId));
            $qty = Decimal::of($line->qty);
            if (! $qty->isPositive()) {
                throw new DomainException(__('inventory.errors.non_positive_qty'));
            }
            $catalogPrice = $this->prices->sellPrice($variation, $data->priceGroupId, $multiplier);
            $shown = $line->price !== null ? Decimal::of($line->price) : $catalogPrice;
            $rate = (string) ($rates[$product->tax_rate_id] ?? 0);

            $discountValue = Decimal::of($line->discountValue);
            if ($line->discountType === DiscountType::Fixed) {
                $discountValue = $this->prices->exclusive($discountValue, $product->tax_type, $rate);
            }

            $prepared[] = compact('variation', 'product', 'unitId', 'multiplier', 'qty', 'shown', 'catalogPrice', 'rate') + ['line' => $line];
            $inputs[] = new LineInput((string) $qty, (string) $this->prices->exclusive($shown, $product->tax_type, $rate),
                $line->discountType, (string) $discountValue, $rate);
        }

        $totals = $this->calculator->calculate($inputs, $data->discountType, $data->discountValue, $rates[$data->taxRateId] ?? 0,
            Decimal::of($data->shippingCharges)->plus(Decimal::of($data->packingCharge)));

        return [$prepared, $totals];
    }

    private function storeLine(Sale $sale, array $p, LineResult $result): void
    {
        /** @var SaleLineData $line */
        $line = $p['line'];
        $item = $sale->items()->create([
            'product_id' => $p['product']->id,
            'variation_id' => $p['variation']->id,
            'unit_id' => $p['unitId'],
            'unit_multiplier' => Decimal::toDb($p['multiplier']),
            'qty' => Decimal::toDb($p['qty']),
            'base_qty' => Decimal::toDb($p['qty']->multipliedBy($p['multiplier'])),
            'unit_price_before_discount' => Decimal::toDb($this->prices->exclusive($p['shown'], $p['product']->tax_type, $p['rate'])),
            'discount_type' => $line->discountType,
            'discount_value' => Decimal::toDb($line->discountValue),
            'unit_price' => Decimal::toDb($result->unitPrice),
            'tax_rate_id' => $p['product']->tax_rate_id,
            'tax_amount' => Decimal::toDb($result->taxPerUnit),
            'unit_price_inc_tax' => Decimal::toDb($result->unitPriceIncTax),
            'line_total' => Decimal::toDb($result->lineTotal),
            'lot_id' => $line->lotId,
            'warranty_id' => $p['product']->warranty_id,
            'service_staff_id' => $line->serviceStaffId,
            'note' => $line->note,
        ]);

        if ($p['product']->type === ProductType::Combo) {
            foreach ($p['variation']->comboItems as $component) {
                $componentMultiplier = $this->units->multiplier($component->componentVariation->product, Unit::query()->findOrFail($component->unit_id));
                $qty = Decimal::of($component->qty)->multipliedBy($p['qty'])->multipliedBy($p['multiplier']);
                $sale->items()->create([
                    'parent_item_id' => $item->id,
                    'child_type' => SaleItemChildType::Combo,
                    'product_id' => $component->componentVariation->product_id,
                    'variation_id' => $component->component_variation_id,
                    'unit_id' => $component->unit_id,
                    'unit_multiplier' => Decimal::toDb($componentMultiplier),
                    'qty' => Decimal::toDb($qty),
                    'base_qty' => Decimal::toDb($qty->multipliedBy($componentMultiplier)),
                ]);
            }
        }
    }

    private function customer(?int $id): Contact
    {
        $customer = $id
            ? Contact::query()->findOrFail($id)
            : Contact::query()->where('is_default', true)->firstOrFail();
        if ($customer->type === ContactType::Supplier) {
            throw new DomainException(__('sales.errors.not_a_customer', ['name' => $customer->name]));
        }

        return $customer;
    }

    private function session(SaleData $data): ?CashSession
    {
        if ($data->channel !== SaleChannel::Pos) {
            return $data->cashSessionId ? CashSession::query()->find($data->cashSessionId) : null;
        }
        $session = $data->cashSessionId ? CashSession::query()->with('cashRegister')->find($data->cashSessionId) : null;
        if ($session === null && $this->pos->require_open_register) {
            throw new DomainException(__('cash.errors.no_session'));
        }
        if ($session !== null && ($session->status !== CashSessionStatus::Open || $session->cashRegister->location_id !== $data->locationId)) {
            throw new DomainException(__('cash.errors.session_closed'));
        }

        return $session;
    }

    /** A cashier may not exceed their personal maximum discount unless they can override limits. */
    private function assertDiscountAllowed(?User $user, array $prepared, DocumentTotals $totals): void
    {
        if (! $user instanceof User || $user->max_discount_percent === null || $user->can('pos.override_limits')) {
            return;
        }
        $gross = BigDecimal::zero();
        foreach ($prepared as $i => $p) {
            $gross = $gross->plus($p['qty']->multipliedBy($p['catalogPrice']));
        }
        if (! $gross->isPositive()) {
            return;
        }
        $net = $totals->subtotal->minus($totals->discountAmount);
        $percent = $gross->minus($net)->multipliedBy(100)->dividedBy($gross, 2, RoundingMode::HalfUp);

        if ($percent->isGreaterThan(Decimal::of($user->max_discount_percent))) {
            throw new DomainException(__('sales.errors.discount_too_high', ['max' => (float) $user->max_discount_percent]));
        }
    }

    /**
     * A price typed by hand, a line discount or an invoice discount may not sell below cost: the branch's average cost,
     * or the purchase price before any stock arrived. Users who can override limits are exempt. The cost itself
     * is only named to users allowed to see purchase prices.
     *
     * @param  list<array<string, mixed>>  $prepared
     */
    private function assertNotBelowCost(?User $user, array $prepared, DocumentTotals $totals, int $locationId): void
    {
        if ($user instanceof User && $user->can('pos.override_limits')) {
            return;
        }
        $edited = array_filter($prepared, fn (array $p) => $p['line']->price !== null || ($p['line']->discountType !== null && Decimal::of($p['line']->discountValue)->isPositive()));
        $invoiceDiscount = $totals->discountAmount->isPositive() && $totals->subtotal->isPositive();
        if ($edited === [] && ! $invoiceDiscount) {
            return;
        }
        $costs = StockLevel::query()->where('location_id', $locationId)->where('avg_cost', '>', 0)
            ->whereIn('variation_id', array_map(fn (array $p) => $p['variation']->id, $prepared))->pluck('avg_cost', 'variation_id');
        $unitCost = fn (array $p) => Decimal::of($costs[$p['variation']->id] ?? $p['variation']->purchase_price ?? 0)->multipliedBy($p['multiplier']);
        $canSeeCost = $user instanceof User && $user->can('products.view_purchase_price');

        if ($invoiceDiscount) {
            // the discount is taken off the total with tax: only its share of the net (before tax) counts
            $net = array_reduce($totals->lines, fn (BigDecimal $c, $r) => $c->plus($r->lineNet), BigDecimal::zero());
            $netAfter = $net->minus($net->multipliedBy($totals->discountAmount)->dividedBy($totals->subtotal, 8, RoundingMode::HalfUp));
            $cost = array_reduce($prepared, fn (BigDecimal $c, array $p) => $c->plus($unitCost($p)->multipliedBy($p['qty'])), BigDecimal::zero());
            if ($cost->isPositive() && $netAfter->isLessThan($cost->toScale(2, RoundingMode::HalfUp))) {
                throw new DomainException($canSeeCost
                    ? __('sales.errors.invoice_below_cost_with', ['cost' => number_format($cost->toFloat(), 2)])
                    : __('sales.errors.invoice_below_cost'));
            }
        }

        foreach ($edited as $p) {
            $line = $p['line'];
            $cost = $unitCost($p);
            if (! $cost->isPositive()) {
                continue;
            }
            $price = $this->prices->exclusive($p['shown'], $p['product']->tax_type, $p['rate']);
            $discount = Decimal::of($line->discountValue);
            $net = match ($line->discountType) {
                DiscountType::Percent => $price->minus($price->multipliedBy(BigDecimal::min($discount, BigDecimal::of(100)))->dividedBy(100, 8, RoundingMode::HalfUp)),
                DiscountType::Fixed => $price->minus($this->prices->exclusive($discount, $p['product']->tax_type, $p['rate'])),
                default => $price,
            };
            if ($net->isLessThan($cost->toScale(2, RoundingMode::HalfUp))) {
                $item = $p['variation']->fullName();
                throw new DomainException($canSeeCost
                    ? __('sales.errors.below_cost_with', ['item' => $item, 'cost' => number_format($cost->toFloat(), 2)])
                    : __('sales.errors.below_cost', ['item' => $item]));
            }
        }
    }

    private function assertCreditAllowed(Contact $customer, Sale $sale, SaleData $data): void
    {
        $due = Decimal::of($sale->due_total);
        if (! $due->isPositive()) {
            return;
        }
        $user = auth()->user();
        if ($customer->is_default) {
            throw new DomainException(__('sales.errors.walk_in_must_pay'));
        }
        if ($data->channel === SaleChannel::Pos && ! $user?->can('pos.credit_sale')) {
            throw new DomainException(__('sales.errors.credit_not_allowed'));
        }
        if ($customer->credit_limit !== null && ! $user?->can('pos.override_limits')) {
            $exposure = Decimal::of($this->ledger->balance($customer)); // already includes this sale
            if ($exposure->isGreaterThan(Decimal::of($customer->credit_limit))) {
                throw new DomainException(__('sales.errors.credit_limit', ['limit' => Decimal::toDb($customer->credit_limit, 2)]));
            }
        }
    }
}
