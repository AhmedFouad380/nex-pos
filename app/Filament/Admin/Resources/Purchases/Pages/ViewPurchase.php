<?php

namespace App\Filament\Admin\Resources\Purchases\Pages;

use App\Domain\Finance\Enums\PaymentDirection;
use App\Domain\Purchasing\Actions\ReceivePurchase;
use App\Domain\Purchasing\Actions\VoidPurchase;
use App\Domain\Purchasing\Enums\PurchaseStatus;
use App\Domain\Purchasing\Models\Purchase;
use App\Filament\Actions\ActivityHistoryAction;
use App\Filament\Admin\Pages\PrintLabels;
use App\Filament\Admin\Resources\PurchaseReturns\PurchaseReturnResource;
use App\Filament\Admin\Resources\Purchases\PurchaseResource;
use App\Filament\Admin\Support\PaymentActions;
use App\Filament\Support\DocumentActions;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Database\Eloquent\Model;

class ViewPurchase extends ViewRecord
{
    protected static string $resource = PurchaseResource::class;

    protected function resolveRecord(int|string $key): Model
    {
        return parent::resolveRecord($key)->load([
            'supplier', 'location', 'creator', 'purchaseOrder', 'items.variation.product', 'items.unit', 'costs',
            'allocations.payment.paymentMethod',
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            DocumentActions::step('receive', __('Receive goods'), 'heroicon-o-inbox-arrow-down', 'primary',
                fn (Purchase $r) => $r->voided_at === null && $r->status === PurchaseStatus::Pending && auth()->user()?->can('purchases.update'),
                fn (Purchase $r) => app(ReceivePurchase::class)->handle($r), $this),
            Action::make('labels')->label(__('Print barcode labels'))->icon('heroicon-o-qr-code')->color('gray')
                ->visible(fn () => PrintLabels::canAccess())
                ->url(fn (Purchase $r) => PrintLabels::getUrl(['purchase' => $r->id])),
            PaymentActions::forDocument(PaymentDirection::Out, fn (Purchase $r) => $r->supplier_id, fn (Purchase $r) => $r->location_id, 'payments.create')
                ->after(fn () => $this->refreshRecord()),
            Action::make('return')->label(__('Return to supplier'))->icon('heroicon-o-arrow-uturn-left')->color('gray')
                ->visible(fn (Purchase $r) => $r->voided_at === null && auth()->user()?->can('purchase_returns.create'))
                ->url(fn (Purchase $r) => PurchaseReturnResource::getUrl('create', ['purchase' => $r->id])),
            DocumentActions::void(fn (Purchase $r, string $reason) => app(VoidPurchase::class)->handle($r, $reason), 'purchases.void', $this),
            ActivityHistoryAction::make(),
        ];
    }

    public function refreshRecord(): void
    {
        $this->record = $this->resolveRecord($this->getRecord()->getKey());
    }
}
