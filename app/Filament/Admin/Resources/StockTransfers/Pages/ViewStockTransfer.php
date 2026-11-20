<?php

namespace App\Filament\Admin\Resources\StockTransfers\Pages;

use App\Domain\Inventory\Actions\CancelStockTransfer;
use App\Domain\Inventory\Actions\ReceiveStockTransfer;
use App\Domain\Inventory\Actions\SendStockTransfer;
use App\Domain\Inventory\Enums\StockTransferStatus;
use App\Domain\Inventory\Models\StockTransfer;
use App\Filament\Actions\ActivityHistoryAction;
use App\Filament\Admin\Resources\StockTransfers\StockTransferResource;
use App\Support\DomainException;
use Closure;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Database\Eloquent\Model;

class ViewStockTransfer extends ViewRecord
{
    protected static string $resource = StockTransferResource::class;

    protected function resolveRecord(int|string $key): Model
    {
        return parent::resolveRecord($key)->load(['fromLocation', 'toLocation', 'creator', 'receiver', 'items.variation.product', 'items.unit']);
    }

    protected function getHeaderActions(): array
    {
        $atLocation = fn (int $locationId) => array_key_exists($locationId, StockTransferResource::userLocationOptions());

        return [
            $this->step('send', __('Send'), 'heroicon-o-truck', 'primary',
                fn (StockTransfer $r) => $r->status === StockTransferStatus::Draft && $atLocation($r->from_location_id) && auth()->user()?->can('stock_transfers.create'),
                fn (StockTransfer $r) => app(SendStockTransfer::class)->handle($r)),
            $this->step('receive', __('Receive'), 'heroicon-o-inbox-arrow-down', 'success',
                fn (StockTransfer $r) => $r->status === StockTransferStatus::InTransit && $atLocation($r->to_location_id) && auth()->user()?->can('stock_transfers.receive'),
                fn (StockTransfer $r) => app(ReceiveStockTransfer::class)->handle($r)),
            $this->step('cancel', __('Cancel transfer'), 'heroicon-o-x-circle', 'danger',
                fn (StockTransfer $r) => in_array($r->status, [StockTransferStatus::Draft, StockTransferStatus::InTransit], true)
                    && $atLocation($r->from_location_id) && auth()->user()?->can('stock_transfers.create'),
                fn (StockTransfer $r) => app(CancelStockTransfer::class)->handle($r)),
            ActivityHistoryAction::make(),
        ];
    }

    private function step(string $name, string $label, string $icon, string $color, Closure $visible, Closure $run): Action
    {
        return Action::make($name)->label($label)->icon($icon)->color($color)
            ->visible($visible)
            ->requiresConfirmation()
            ->action(function (StockTransfer $record) use ($run) {
                try {
                    $run($record);
                    Notification::make()->success()->title(__('Saved'))->send();
                } catch (DomainException $e) {
                    Notification::make()->danger()->title($e->getMessage())->persistent()->send();
                }
                $this->record = $this->resolveRecord($record->getKey());
            });
    }
}
