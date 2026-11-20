<?php

namespace App\Filament\Admin\Resources\Customers\Pages;

use App\Domain\CRM\Enums\BalanceDirection;
use App\Domain\Finance\Enums\PaymentDirection;
use App\Filament\Actions\ActivityHistoryAction;
use App\Filament\Admin\Resources\Customers\CustomerResource;
use App\Filament\Admin\Support\ContactPageActions;
use App\Filament\Admin\Support\PaymentActions;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewCustomer extends ViewRecord
{
    protected static string $resource = CustomerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            PaymentActions::forContact('paymentIn', PaymentDirection::In, __('Receive payment'), 'payments.create'),
            Action::make('statement')->label(__('Account statement'))->icon('heroicon-o-document-text')->color('gray')
                ->url(fn () => CustomerResource::getUrl('statement', ['record' => $this->getRecord()]))
                ->visible(fn () => auth()->user()?->can('contacts.ledger')),
            EditAction::make(),
            ActionGroup::make([
                PaymentActions::forContact('paymentOut', PaymentDirection::Out, __('Refund'), 'payments.create'),
                ContactPageActions::openingBalance(BalanceDirection::Receivable),
                ContactPageActions::ledgerDiscount(BalanceDirection::Receivable),
            ]),
            ActivityHistoryAction::make(),
        ];
    }
}
