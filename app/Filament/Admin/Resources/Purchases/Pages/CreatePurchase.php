<?php

namespace App\Filament\Admin\Resources\Purchases\Pages;

use App\Domain\Finance\Enums\PaymentDirection;
use App\Domain\Purchasing\Actions\CreatePurchase as CreatePurchaseAction;
use App\Domain\Purchasing\Actions\CreatePurchaseOrder;
use App\Domain\Purchasing\Data\PurchaseData;
use App\Domain\Purchasing\Data\PurchaseLineData;
use App\Domain\Purchasing\Enums\PurchaseOrderStatus;
use App\Domain\Purchasing\Enums\PurchaseStatus;
use App\Domain\Purchasing\Models\PurchaseOrder;
use App\Domain\Shared\Enums\DiscountType;
use App\Filament\Admin\Resources\Purchases\PurchaseResource;
use App\Filament\Admin\Support\PaymentActions;
use App\Filament\Concerns\HandlesDomainExceptions;
use App\Filament\Support\UserLocations;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Url;

class CreatePurchase extends CreateRecord
{
    use HandlesDomainExceptions;

    protected static string $resource = PurchaseResource::class;

    protected static bool $canCreateAnother = false;

    #[Url(as: 'purchase_order')]
    public ?int $purchaseOrderId = null;

    /** Opening from a purchase order prefills supplier, location and the quantities still to receive. */
    protected function fillForm(): void
    {
        $order = $this->purchaseOrderId ? PurchaseOrder::query()->find($this->purchaseOrderId) : null;
        if (! $order || in_array($order->status, [PurchaseOrderStatus::Received, PurchaseOrderStatus::Cancelled], true)) {
            parent::fillForm();

            return;
        }

        $this->form->fill([
            'supplier_id' => $order->supplier_id,
            'location_id' => $order->location_id,
            'purchase_date' => now(),
            'status' => PurchaseStatus::Received->value,
            'items' => CreatePurchaseOrder::remainingLines($order),
            'discount_type' => $order->discount_type?->value,
            'discount_value' => (float) $order->discount_value,
            'tax_rate_id' => $order->tax_rate_id,
            'shipping_charges' => 0,
            'paid_now' => 0,
        ]);
    }

    protected function handleRecordCreation(array $data): Model
    {
        abort_unless(array_key_exists($data['location_id'], UserLocations::options()), 403);

        return $this->rescueDomain(function () use ($data) {
            $purchase = app(CreatePurchaseAction::class)->handle(new PurchaseData(
                supplierId: (int) $data['supplier_id'],
                locationId: (int) $data['location_id'],
                lines: array_map(fn (array $l) => PurchaseLineData::fromArray([...$l, 'discount_type' => (float) ($l['discount_value'] ?? 0) > 0 ? DiscountType::Percent : null]), array_values($data['items'])),
                status: PurchaseStatus::coerce($data['status'] ?? '') ?? PurchaseStatus::Received,
                date: Carbon::parse($data['purchase_date']),
                dueDate: $data['due_date'] ?? null,
                supplierRef: $data['supplier_ref'] ?? null,
                currencyId: $data['currency_id'] ?? null,
                exchangeRate: (string) (filled($data['currency_id'] ?? null) ? ($data['exchange_rate'] ?? 1) : 1),
                discountType: DiscountType::coerce($data['discount_type'] ?? ''),
                discountValue: (string) ($data['discount_value'] ?? 0),
                taxRateId: filled($data['tax_rate_id'] ?? null) ? (int) $data['tax_rate_id'] : null,
                shippingCharges: (string) ($data['shipping_charges'] ?? 0),
                costs: array_values($data['costs'] ?? []),
                note: $data['note'] ?? null,
                purchaseOrderId: $this->purchaseOrderId,
            ));

            if ((float) ($data['paid_now'] ?? 0) > 0) {
                PaymentActions::record(PaymentDirection::Out, $purchase->supplier_id, $purchase->location_id,
                    ['payment_method_id' => $data['payment_method_id'], 'amount' => $data['paid_now'], 'reference' => $data['payment_reference'] ?? null],
                    [[$purchase, (string) min((float) $data['paid_now'], (float) $purchase->due_total)]]);
            }

            return $purchase;
        });
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
