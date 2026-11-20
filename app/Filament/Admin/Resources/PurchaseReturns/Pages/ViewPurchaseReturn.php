<?php

namespace App\Filament\Admin\Resources\PurchaseReturns\Pages;

use App\Domain\Finance\Enums\PaymentDirection;
use App\Domain\Purchasing\Actions\VoidPurchaseReturn;
use App\Domain\Purchasing\Models\PurchaseReturn;
use App\Filament\Actions\ActivityHistoryAction;
use App\Filament\Admin\Resources\PurchaseReturns\PurchaseReturnResource;
use App\Filament\Admin\Support\PaymentActions;
use App\Filament\Support\DocumentActions;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Database\Eloquent\Model;

class ViewPurchaseReturn extends ViewRecord
{
    protected static string $resource = PurchaseReturnResource::class;

    protected function resolveRecord(int|string $key): Model
    {
        return parent::resolveRecord($key)->load(['supplier', 'location', 'purchase', 'items.variation.product', 'items.unit', 'allocations.payment.paymentMethod']);
    }

    protected function getHeaderActions(): array
    {
        return [
            // the supplier refunds us: money IN allocated to the return
            PaymentActions::forDocument(PaymentDirection::In, fn (PurchaseReturn $r) => $r->supplier_id, fn (PurchaseReturn $r) => $r->location_id, 'payments.create')
                ->label(__('Receive refund'))->after(fn () => $this->refreshRecord()),
            DocumentActions::void(fn (PurchaseReturn $r, string $reason) => app(VoidPurchaseReturn::class)->handle($r, $reason), 'purchases.void', $this),
            ActivityHistoryAction::make(),
        ];
    }

    public function refreshRecord(): void
    {
        $this->record = $this->resolveRecord($this->getRecord()->getKey());
    }
}
