<?php

namespace App\Filament\Admin\Resources\AccountTransfers\Pages;

use App\Domain\Finance\Actions\TransferBetweenAccounts;
use App\Filament\Admin\Resources\AccountTransfers\AccountTransferResource;
use App\Filament\Concerns\HandlesDomainExceptions;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Illuminate\Support\Carbon;

class ManageAccountTransfers extends ManageRecords
{
    use HandlesDomainExceptions;

    protected static string $resource = AccountTransferResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label(__('New transfer'))->using(fn (array $data) => $this->rescueDomain(fn () => app(TransferBetweenAccounts::class)->handle(
                (int) $data['from_account_id'], (int) $data['to_account_id'], $data['amount'], Carbon::parse($data['transferred_at']), $data['note'] ?? null,
            ))),
        ];
    }
}
