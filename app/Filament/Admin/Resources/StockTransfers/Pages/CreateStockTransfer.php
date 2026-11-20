<?php

namespace App\Filament\Admin\Resources\StockTransfers\Pages;

use App\Domain\Inventory\Actions\CreateStockTransfer as CreateStockTransferAction;
use App\Domain\Inventory\Data\StockLineData;
use App\Filament\Admin\Resources\StockTransfers\StockTransferResource;
use App\Filament\Concerns\HandlesDomainExceptions;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class CreateStockTransfer extends CreateRecord
{
    use HandlesDomainExceptions;

    protected static string $resource = StockTransferResource::class;

    protected static bool $canCreateAnother = false;

    protected function handleRecordCreation(array $data): Model
    {
        abort_unless(array_key_exists($data['from_location_id'], StockTransferResource::userLocationOptions()), 403);

        return $this->rescueDomain(fn () => app(CreateStockTransferAction::class)->handle(
            fromLocationId: (int) $data['from_location_id'],
            toLocationId: (int) $data['to_location_id'],
            lines: array_map(StockLineData::fromArray(...), array_values($data['items'])),
            date: Carbon::parse($data['transfer_date']),
            note: $data['note'] ?? null,
            shippingCost: $data['shipping_cost'] ?? null,
            sendNow: (bool) ($data['send_now'] ?? false),
        ));
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
