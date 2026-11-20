<?php

namespace App\Domain\Printing\Services;

use App\Domain\Foundation\Models\Location;
use App\Domain\Sales\Enums\SaleStatus;
use App\Domain\Sales\Models\Sale;
use App\Domain\Sales\Models\SaleReturn;
use App\Support\Decimal;

/** A sale / return reduced to what any printout needs, so A4, A5 and thermal views share one source. */
final readonly class PrintableDocument
{
    /**
     * @param  list<array{name: string, sku: string, qty: string, unit: ?string, price: string, total: string}>  $lines
     * @param  list<array{0: string, 1: string}>  $totals  caption + amount, in order
     * @param  list<array{0: string, 1: string}>  $payments  method + amount
     */
    public function __construct(
        public string $headingKey,
        public string $number,
        public \DateTimeInterface $date,
        public Location $location,
        public ?string $customerName,
        public ?string $customerMobile,
        public bool $walkIn,
        public ?string $cashier,
        public array $lines,
        public array $totals,
        public string $total,
        public array $payments,
        public string $due,
        public ?string $previousBalance,
        public bool $voided,
        public ?string $note,
    ) {}

    public static function fromSale(Sale $sale): self
    {
        $sale->loadMissing(['customer', 'location', 'creator', 'items' => fn ($q) => $q->whereNull('parent_item_id')->with(['variation.product', 'unit']),
            'allocations.payment.paymentMethod']);
        $live = $sale->status === SaleStatus::Final && $sale->voided_at === null;

        return new self(
            headingKey: match ($sale->status) {
                SaleStatus::Final => 'invoice_heading',
                SaleStatus::Quotation, SaleStatus::Proforma => 'quotation_heading',
                default => 'draft_heading',
            },
            number: (string) ($sale->invoice_no ?? $sale->draft_no),
            date: $sale->sale_date,
            location: $sale->location,
            customerName: $sale->customer->name,
            customerMobile: $sale->customer->mobile,
            walkIn: (bool) $sale->customer->is_default,
            cashier: $sale->creator?->name,
            lines: $sale->items->map(fn ($item) => [
                'name' => $item->variation->fullName(),
                'sku' => $item->variation->sku,
                'qty' => $item->qty,
                'unit' => $item->unit_multiplier != 1 ? $item->unit->short_name : null,
                'price' => $item->unit_price_inc_tax,
                'total' => $item->line_total,
            ])->values()->all(),
            totals: array_values(array_filter([
                ['subtotal', $sale->subtotal],
                Decimal::of($sale->discount_amount)->isZero() ? null : ['discount', '-'.$sale->discount_amount],
                Decimal::of($sale->tax_amount)->isZero() ? null : ['tax', $sale->tax_amount],
                Decimal::of($sale->shipping_charges)->plus(Decimal::of($sale->packing_charge))->isZero() ? null
                    : ['charges', Decimal::toDb(Decimal::of($sale->shipping_charges)->plus(Decimal::of($sale->packing_charge)))],
                Decimal::of($sale->round_off)->isZero() ? null : ['round_off', $sale->round_off],
            ])),
            total: $sale->total,
            payments: $sale->allocations->filter(fn ($a) => $a->payment->voided_at === null)
                ->map(fn ($a) => [$a->payment->paymentMethod->name, $a->amount])->values()->all(),
            due: $live ? $sale->due_total : '0',
            // what the customer owed before this invoice
            previousBalance: $live && ! $sale->customer->is_default
                ? Decimal::toDb(Decimal::of($sale->customer->balance)->minus(Decimal::of($sale->due_total))) : null,
            voided: $sale->voided_at !== null,
            note: $sale->getAttribute('note'),
        );
    }

    public static function fromReturn(SaleReturn $return): self
    {
        $return->loadMissing(['customer', 'location', 'creator', 'sale', 'items.variation.product', 'items.unit', 'allocations.payment.paymentMethod']);

        return new self(
            headingKey: 'return_heading',
            number: $return->number,
            date: $return->return_date,
            location: $return->location,
            customerName: $return->customer->name,
            customerMobile: $return->customer->mobile,
            walkIn: (bool) $return->customer->is_default,
            cashier: $return->creator?->name,
            lines: $return->items->map(fn ($item) => [
                'name' => $item->variation->fullName(),
                'sku' => $item->variation->sku,
                'qty' => $item->qty,
                'unit' => $item->unit_multiplier != 1 ? $item->unit->short_name : null,
                'price' => $item->unit_price,
                'total' => $item->line_total,
            ])->values()->all(),
            totals: Decimal::of($return->tax_amount)->isZero() ? [] : [['tax', $return->tax_amount]],
            total: $return->total,
            payments: $return->allocations->filter(fn ($a) => $a->payment->voided_at === null)
                ->map(fn ($a) => [$a->payment->paymentMethod->name, $a->amount])->values()->all(),
            due: $return->voided_at === null ? $return->due_total : '0',
            previousBalance: null,
            voided: $return->voided_at !== null,
            note: $return->sale ? __('For invoice').' '.$return->sale->invoice_no : $return->reason,
        );
    }
}
