<?php

namespace App\Filament\Admin\Resources\StockAdjustments\Pages;

use App\Domain\Inventory\Actions\VoidStockAdjustment;
use App\Domain\Inventory\Models\StockAdjustment;
use App\Filament\Actions\ActivityHistoryAction;
use App\Filament\Admin\Resources\StockAdjustments\StockAdjustmentResource;
use App\Support\DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Database\Eloquent\Model;

class ViewStockAdjustment extends ViewRecord
{
    protected static string $resource = StockAdjustmentResource::class;

    protected function resolveRecord(int|string $key): Model
    {
        return parent::resolveRecord($key)->load(['location', 'creator', 'items.variation.product', 'items.unit', 'items.lot']);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('void')
                ->label(__('Void'))->icon('heroicon-o-no-symbol')->color('danger')
                ->visible(fn (StockAdjustment $record) => $record->voided_at === null && auth()->user()?->can('stock_adjustments.void'))
                ->requiresConfirmation()
                ->modalDescription(__('All stock movements of this document will be reversed.'))
                ->schema([TextInput::make('reason')->label(__('Void reason'))->required()->maxLength(255)])
                ->action(function (StockAdjustment $record, array $data) {
                    try {
                        app(VoidStockAdjustment::class)->handle($record, $data['reason']);
                        Notification::make()->success()->title(__('Voided'))->send();
                    } catch (DomainException $e) {
                        Notification::make()->danger()->title($e->getMessage())->persistent()->send();
                    }
                    $this->refreshFormData([]);
                    $this->record = $this->resolveRecord($record->getKey());
                }),
            ActivityHistoryAction::make(),
        ];
    }
}
