<?php

namespace App\Filament\Admin\Resources\StockCounts\Pages;

use App\Domain\Inventory\Actions\StartStockCount;
use App\Domain\Inventory\Enums\StockCountScope;
use App\Filament\Admin\Resources\StockCounts\StockCountResource;
use App\Support\DomainException;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

/** Starts a count: every stock item of the branch (or of some categories / brands) with what it should have. */
class CreateStockCount extends CreateRecord
{
    protected static string $resource = StockCountResource::class;

    protected static bool $canCreateAnother = false;

    protected function handleRecordCreation(array $data): Model
    {
        try {
            return app(StartStockCount::class)->handle((int) $data['location_id'], StockCountScope::from($data['scope']),
                array_map('intval', $data['category_ids'] ?? []), array_map('intval', $data['brand_ids'] ?? []), $data['note'] ?? null);
        } catch (DomainException $e) {
            Notification::make()->danger()->title($e->getMessage())->persistent()->send();

            throw new Halt;
        }
    }

    protected function getRedirectUrl(): string
    {
        return StockCountResource::getUrl('view', ['record' => $this->getRecord()]);
    }
}
