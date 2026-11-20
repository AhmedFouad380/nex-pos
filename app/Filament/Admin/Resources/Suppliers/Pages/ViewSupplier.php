<?php

namespace App\Filament\Admin\Resources\Suppliers\Pages;

use App\Domain\CRM\Enums\BalanceDirection;
use App\Domain\Finance\Enums\PaymentDirection;
use App\Filament\Actions\ActivityHistoryAction;
use App\Filament\Admin\Resources\Suppliers\SupplierResource;
use App\Filament\Admin\Support\ContactPageActions;
use App\Filament\Admin\Support\PaymentActions;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewSupplier extends ViewRecord
{
    protected static string $resource = SupplierResource::class;

    protected function getHeaderActions(): array
    {
        return [
            PaymentActions::forContact('paymentIn', PaymentDirection::Out, __('Pay supplier'), 'payments.create'),
            Action::make('statement')->label(__('Account statement'))->icon('heroicon-o-document-text')->color('gray')
                ->url(fn () => SupplierResource::getUrl('statement', ['record' => $this->getRecord()]))
                ->visible(fn () => auth()->user()?->can('contacts.ledger')),
            EditAction::make(),
            ActionGroup::make([
                PaymentActions::forContact('paymentOut', PaymentDirection::In, __('Receive refund'), 'payments.create'),
                ContactPageActions::openingBalance(BalanceDirection::Payable),
                ContactPageActions::ledgerDiscount(BalanceDirection::Payable),
            ]),
            ActivityHistoryAction::make(),
        ];
    }
}
