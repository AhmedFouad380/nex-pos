<?php

namespace App\Filament\Admin\Resources\Sales\Pages;

use App\Domain\Finance\Enums\PaymentDirection;
use App\Domain\Sales\Actions\VoidSale;
use App\Domain\Sales\Enums\SaleStatus;
use App\Domain\Sales\Models\Sale;
use App\Filament\Actions\ActivityHistoryAction;
use App\Filament\Admin\Resources\SaleReturns\SaleReturnResource;
use App\Filament\Admin\Resources\Sales\SaleResource;
use App\Filament\Admin\Support\PaymentActions;
use App\Filament\Support\DocumentActions;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Database\Eloquent\Model;

class ViewSale extends ViewRecord
{
    protected static string $resource = SaleResource::class;

    protected function resolveRecord(int|string $key): Model
    {
        return parent::resolveRecord($key)->load([
            'customer', 'location', 'creator', 'items' => fn ($q) => $q->whereNull('parent_item_id')->with(['variation.product', 'unit']),
            'allocations.payment.paymentMethod',
        ]);
    }

    protected function getHeaderActions(): array
    {
        $final = fn (Sale $r) => $r->status === SaleStatus::Final && $r->voided_at === null;

        return [
            PaymentActions::forDocument(PaymentDirection::In, fn (Sale $r) => $r->customer_id, fn (Sale $r) => $r->location_id, 'payments.create')
                ->label(__('Receive payment'))->after(fn () => $this->refreshRecord()),
            ActionGroup::make([
                Action::make('invoice')->label(__('Print invoice (A4)'))->icon('heroicon-o-document-text')
                    ->url(fn (Sale $r) => route('print.sale', $r), shouldOpenInNewTab: true),
                Action::make('receipt')->label(__('Print receipt'))->icon('heroicon-o-receipt-percent')
                    ->url(fn (Sale $r) => route('pos.receipt', $r), shouldOpenInNewTab: true),
            ])->label(__('Print'))->icon('heroicon-o-printer')->color('gray')->button(),
            Action::make('return')->label(__('Return'))->icon('heroicon-o-arrow-uturn-left')->color('gray')
                ->visible(fn (Sale $r) => $final($r) && auth()->user()?->can('sale_returns.create'))
                ->url(fn (Sale $r) => SaleReturnResource::getUrl('create', ['sale' => $r->id])),
            DocumentActions::void(fn (Sale $r, string $reason) => app(VoidSale::class)->handle($r, $reason), 'sales.void', $this)
                ->visible(fn (Sale $r) => $final($r) && auth()->user()?->can('sales.void'))
                ->modalDescription(__('Stock goes back and the payments of this invoice are voided.')),
            ActivityHistoryAction::make(),
        ];
    }

    public function refreshRecord(): void
    {
        $this->record = $this->resolveRecord($this->getRecord()->getKey());
    }
}
