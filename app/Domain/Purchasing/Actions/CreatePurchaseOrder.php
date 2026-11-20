<?php

namespace App\Domain\Purchasing\Actions;

use App\Domain\Catalog\Models\TaxRate;
use App\Domain\Catalog\Models\Unit;
use App\Domain\Catalog\Models\Variation;
use App\Domain\Catalog\Services\UnitConverter;
use App\Domain\Foundation\Services\DocumentNumberGenerator;
use App\Domain\Purchasing\Data\PurchaseLineData;
use App\Domain\Purchasing\Enums\PurchaseOrderStatus;
use App\Domain\Purchasing\Models\PurchaseOrder;
use App\Domain\Shared\Data\LineInput;
use App\Domain\Shared\Enums\DiscountType;
use App\Domain\Shared\Enums\DocumentType;
use App\Domain\Shared\Services\DocumentCalculator;
use App\Support\Decimal;
use App\Support\DomainException;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/** A purchase order to a supplier. Moves no stock and no money; purchases are created from it later. */
final class CreatePurchaseOrder
{
    public function __construct(
        private readonly DocumentCalculator $calculator,
        private readonly UnitConverter $units,
        private readonly DocumentNumberGenerator $numbers,
    ) {}

    /** @param list<PurchaseLineData> $lines */
    public function handle(int $supplierId, int $locationId, array $lines, ?CarbonInterface $date = null, ?string $expectedAt = null,
        ?DiscountType $discountType = null, string $discountValue = '0', ?int $taxRateId = null, string $shipping = '0',
        ?string $note = null, bool $sent = false): PurchaseOrder
    {
        if ($lines === []) {
            throw new DomainException(__('inventory.errors.no_items'));
        }

        return DB::transaction(function () use ($supplierId, $locationId, $lines, $date, $expectedAt, $discountType, $discountValue, $taxRateId, $shipping, $note, $sent) {
            $rates = TaxRate::query()->pluck('rate', 'id');
            $totals = $this->calculator->calculate(
                array_map(fn ($l) => new LineInput($l->qty, $l->unitCost, $l->discountType, $l->discountValue, (string) ($rates[$l->taxRateId] ?? 0)), $lines),
                $discountType, $discountValue, $rates[$taxRateId] ?? 0, $shipping,
            );

            $order = PurchaseOrder::query()->create([
                'number' => $this->numbers->next(DocumentType::PurchaseOrder, $locationId),
                'supplier_id' => $supplierId,
                'location_id' => $locationId,
                'order_date' => $date ?? now(),
                'expected_at' => $expectedAt,
                'status' => $sent ? PurchaseOrderStatus::Sent : PurchaseOrderStatus::Draft,
                'subtotal' => Decimal::toDb($totals->subtotal),
                'discount_type' => $discountType,
                'discount_value' => Decimal::toDb($discountValue),
                'discount_amount' => Decimal::toDb($totals->discountAmount),
                'tax_rate_id' => $taxRateId,
                'tax_amount' => Decimal::toDb($totals->taxAmount),
                'shipping_charges' => Decimal::toDb($shipping),
                'total' => Decimal::toDb($totals->total),
                'note' => $note,
                'created_by' => auth()->id(),
            ]);

            foreach ($lines as $i => $line) {
                $variation = Variation::query()->with('product')->findOrFail($line->variationId);
                $order->items()->create([
                    'variation_id' => $variation->id,
                    'unit_id' => $line->unitId,
                    'unit_multiplier' => Decimal::toDb($this->units->multiplier($variation->product, Unit::query()->findOrFail($line->unitId))),
                    'qty' => Decimal::toDb($line->qty),
                    'unit_cost' => Decimal::toDb($line->unitCost),
                    'discount_type' => $line->discountType,
                    'discount_value' => Decimal::toDb($line->discountValue),
                    'tax_rate_id' => $line->taxRateId,
                    'tax_amount' => Decimal::toDb($totals->lines[$i]->taxPerUnit),
                    'line_total' => Decimal::toDb($totals->lines[$i]->lineTotal),
                ]);
            }

            return $order->refresh();
        });
    }

    /** Lines still to receive, ready to prefill a purchase form. @return list<array<string, mixed>> */
    public static function remainingLines(PurchaseOrder $order): array
    {
        return $order->items()->get()
            ->map(fn ($item) => [
                'purchase_order_item_id' => $item->id,
                'variation_id' => $item->variation_id,
                'unit_id' => $item->unit_id,
                'qty' => Decimal::of($item->qty)->minus(Decimal::of($item->qty_received))->toFloat(),
                'unit_cost' => (float) $item->unit_cost,
                'discount_type' => $item->discount_type?->value,
                'discount_value' => (float) $item->discount_value,
                'tax_rate_id' => $item->tax_rate_id,
            ])
            ->filter(fn ($line) => $line['qty'] > 0)
            ->values()->all();
    }
}
