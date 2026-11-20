<?php

namespace App\Filament\Admin\Resources\Accounts\Pages;

use App\Domain\Finance\Actions\SaveAccount;
use App\Filament\Admin\Resources\Accounts\AccountResource;
use App\Filament\Concerns\HandlesDomainExceptions;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageAccounts extends ManageRecords
{
    use HandlesDomainExceptions;

    protected static string $resource = AccountResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->using(fn (array $data) => $this->rescueDomain(fn () => app(SaveAccount::class)->handle($data))),
        ];
    }
}
