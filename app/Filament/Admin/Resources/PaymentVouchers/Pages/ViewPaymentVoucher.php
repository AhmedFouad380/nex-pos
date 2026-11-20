<?php

namespace App\Filament\Admin\Resources\PaymentVouchers\Pages;

use App\Domain\Finance\Actions\VoidPayment;
use App\Domain\Finance\Models\Payment;
use App\Filament\Actions\ActivityHistoryAction;
use App\Filament\Admin\Resources\PaymentVouchers\PaymentVoucherResource;
use App\Filament\Support\DocumentActions;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Database\Eloquent\Model;

class ViewPaymentVoucher extends ViewRecord
{
    protected static string $resource = PaymentVoucherResource::class;

    protected function resolveRecord(int|string $key): Model
    {
        return parent::resolveRecord($key)->load(['contact', 'counterAccount', 'paymentMethod', 'account', 'creator', 'allocations.allocatable']);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('print')->label(__('Print voucher'))->icon('heroicon-o-printer')->color('gray')
                ->url(fn (Payment $record) => route('vouchers.print', $record), shouldOpenInNewTab: true),
            DocumentActions::void(fn (Payment $r, string $reason) => app(VoidPayment::class)->handle($r, $reason), 'payments.void', $this)
                ->modalDescription(__('The documents this payment settled will become due again.')),
            ActivityHistoryAction::make(),
        ];
    }

    public function refreshRecord(): void
    {
        $this->record = $this->resolveRecord($this->getRecord()->getKey());
    }
}
