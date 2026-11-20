<?php

namespace App\Filament\Admin\Resources\PurchaseOrders\Pages;

use App\Domain\Purchasing\Actions\CancelPurchaseOrder;
use App\Domain\Purchasing\Enums\PurchaseOrderStatus;
use App\Domain\Purchasing\Models\PurchaseOrder;
use App\Filament\Actions\ActivityHistoryAction;
use App\Filament\Admin\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Filament\Admin\Resources\Purchases\PurchaseResource;
use App\Filament\Support\DocumentActions;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Database\Eloquent\Model;

class ViewPurchaseOrder extends ViewRecord
{
    protected static string $resource = PurchaseOrderResource::class;

    protected function resolveRecord(int|string $key): Model
    {
        return parent::resolveRecord($key)->load(['supplier', 'location', 'items.variation.product', 'items.unit']);
    }

    protected function getHeaderActions(): array
    {
        $open = fn (PurchaseOrder $r) => in_array($r->status, [PurchaseOrderStatus::Draft, PurchaseOrderStatus::Sent, PurchaseOrderStatus::Partial], true);

        return [
            Action::make('receive')->label(__('Receive as purchase'))->icon('heroicon-o-inbox-arrow-down')
                ->visible(fn (PurchaseOrder $r) => $open($r) && auth()->user()?->can('purchases.create'))
                ->url(fn (PurchaseOrder $r) => PurchaseResource::getUrl('create', ['purchase_order' => $r->id])),
            DocumentActions::step('markSent', __('Mark as sent'), 'heroicon-o-paper-airplane', 'gray',
                fn (PurchaseOrder $r) => $r->status === PurchaseOrderStatus::Draft,
                fn (PurchaseOrder $r) => $r->update(['status' => PurchaseOrderStatus::Sent]), $this),
            DocumentActions::step('cancel', __('Cancel order'), 'heroicon-o-x-circle', 'danger', $open,
                fn (PurchaseOrder $r) => app(CancelPurchaseOrder::class)->handle($r), $this),
            ActivityHistoryAction::make(),
        ];
    }

    public function refreshRecord(): void
    {
        $this->record = $this->resolveRecord($this->getRecord()->getKey());
    }
}
