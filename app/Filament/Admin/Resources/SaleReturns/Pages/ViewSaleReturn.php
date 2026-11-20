<?php

namespace App\Filament\Admin\Resources\SaleReturns\Pages;

use App\Domain\Finance\Enums\PaymentDirection;
use App\Domain\Sales\Actions\VoidSaleReturn;
use App\Domain\Sales\Models\SaleReturn;
use App\Filament\Actions\ActivityHistoryAction;
use App\Filament\Admin\Resources\SaleReturns\SaleReturnResource;
use App\Filament\Admin\Support\PaymentActions;
use App\Filament\Support\DocumentActions;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Database\Eloquent\Model;

class ViewSaleReturn extends ViewRecord
{
    protected static string $resource = SaleReturnResource::class;

    protected function resolveRecord(int|string $key): Model
    {
        return parent::resolveRecord($key)->load(['customer', 'sale', 'items.variation.product', 'allocations.payment.paymentMethod']);
    }

    protected function getHeaderActions(): array
    {
        return [
            PaymentActions::forDocument(PaymentDirection::Out, fn (SaleReturn $r) => $r->customer_id, fn (SaleReturn $r) => $r->location_id, 'payments.create')
                ->label(__('Refund customer'))->after(fn () => $this->refreshRecord()),
            Action::make('print')->label(__('Print'))->icon('heroicon-o-printer')->color('gray')
                ->url(fn (SaleReturn $r) => route('print.sale_return', $r), shouldOpenInNewTab: true),
            DocumentActions::void(fn (SaleReturn $r, string $reason) => app(VoidSaleReturn::class)->handle($r, $reason), 'sales.void', $this),
            ActivityHistoryAction::make(),
        ];
    }

    public function refreshRecord(): void
    {
        $this->record = $this->resolveRecord($this->getRecord()->getKey());
    }
}
