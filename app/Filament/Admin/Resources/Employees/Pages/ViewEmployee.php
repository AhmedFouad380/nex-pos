<?php

namespace App\Filament\Admin\Resources\Employees\Pages;

use App\Domain\CRM\Enums\BalanceDirection;
use App\Domain\CRM\Models\Contact;
use App\Domain\Finance\Enums\PaymentDirection;
use App\Filament\Actions\ActivityHistoryAction;
use App\Filament\Admin\Resources\EmployeeAdvances\AdvanceActions;
use App\Filament\Admin\Resources\Employees\EmployeeResource;
use App\Filament\Admin\Resources\Employees\Widgets\EmployeeOverview;
use App\Filament\Admin\Support\ContactPageActions;
use App\Filament\Admin\Support\PaymentActions;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewEmployee extends ViewRecord
{
    protected static string $resource = EmployeeResource::class;

    protected function getHeaderWidgets(): array
    {
        return [EmployeeOverview::class];
    }

    public function getHeaderWidgetsColumns(): int|array
    {
        return 1;
    }

    protected function getHeaderActions(): array
    {
        return [
            AdvanceActions::give(function (): Contact {
                $record = $this->getRecord();
                assert($record instanceof Contact);

                return $record;
            }),
            PaymentActions::forContact('paymentIn', PaymentDirection::Out, __('Pay employee'), 'payments.create'),
            Action::make('statement')->label(__('Account statement'))->icon('heroicon-o-document-text')->color('gray')
                ->url(fn () => EmployeeResource::getUrl('statement', ['record' => $this->getRecord()]))
                ->visible(fn () => auth()->user()?->can('contacts.ledger')),
            EditAction::make(),
            ActionGroup::make([
                PaymentActions::forContact('paymentOut', PaymentDirection::In, __('Receive from employee'), 'payments.create'),
                ContactPageActions::openingBalance(BalanceDirection::Receivable),
            ]),
            ActivityHistoryAction::make(fn (Contact $record) => [$record->employeeProfile]),
        ];
    }
}
