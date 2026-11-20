<?php

namespace App\Filament\Admin\Resources\StockAdjustments\Pages;

use App\Domain\Inventory\Actions\CreateStockAdjustment as CreateStockAdjustmentAction;
use App\Domain\Inventory\Data\StockLineData;
use App\Domain\Inventory\Enums\StockAdjustmentType;
use App\Filament\Admin\Resources\StockAdjustments\StockAdjustmentResource;
use App\Filament\Concerns\HandlesDomainExceptions;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class CreateStockAdjustment extends CreateRecord
{
    use HandlesDomainExceptions;

    protected static string $resource = StockAdjustmentResource::class;

    protected static bool $canCreateAnother = false;

    protected function handleRecordCreation(array $data): Model
    {
        abort_unless(array_key_exists($data['location_id'], StockAdjustmentResource::userLocationOptions()), 403);
        abort_unless(array_key_exists($data['type'], StockAdjustmentResource::typeOptions()), 403);

        return $this->rescueDomain(fn () => app(CreateStockAdjustmentAction::class)->handle(
            locationId: (int) $data['location_id'],
            type: StockAdjustmentType::from($data['type']),
            lines: array_map(StockLineData::fromArray(...), array_values($data['items'])),
            date: Carbon::parse($data['adjusted_at']),
            reason: $data['reason'] ?? null,
            note: $data['note'] ?? null,
            recoveredAmount: $data['recovered_amount'] ?? null,
        ));
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
