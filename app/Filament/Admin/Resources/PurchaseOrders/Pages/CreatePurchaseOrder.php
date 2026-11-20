<?php

namespace App\Filament\Admin\Resources\PurchaseOrders\Pages;

use App\Domain\Purchasing\Actions\CreatePurchaseOrder as CreatePurchaseOrderAction;
use App\Domain\Purchasing\Data\PurchaseLineData;
use App\Domain\Shared\Enums\DiscountType;
use App\Filament\Admin\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Filament\Concerns\HandlesDomainExceptions;
use App\Filament\Support\UserLocations;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class CreatePurchaseOrder extends CreateRecord
{
    use HandlesDomainExceptions;

    protected static string $resource = PurchaseOrderResource::class;

    protected static bool $canCreateAnother = false;

    protected function handleRecordCreation(array $data): Model
    {
        abort_unless(array_key_exists($data['location_id'], UserLocations::options()), 403);

        return $this->rescueDomain(fn () => app(CreatePurchaseOrderAction::class)->handle(
            supplierId: (int) $data['supplier_id'],
            locationId: (int) $data['location_id'],
            lines: array_map(fn (array $l) => PurchaseLineData::fromArray([...$l, 'discount_type' => (float) ($l['discount_value'] ?? 0) > 0 ? DiscountType::Percent : null]), array_values($data['items'])),
            date: Carbon::parse($data['order_date']),
            expectedAt: $data['expected_at'] ?? null,
            discountType: DiscountType::coerce($data['discount_type'] ?? ''),
            discountValue: (string) ($data['discount_value'] ?? 0),
            taxRateId: filled($data['tax_rate_id'] ?? null) ? (int) $data['tax_rate_id'] : null,
            shipping: (string) ($data['shipping_charges'] ?? 0),
            note: $data['note'] ?? null,
        ));
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
